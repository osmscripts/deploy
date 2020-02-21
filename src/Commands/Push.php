<?php

namespace OsmScripts\Deploy\Commands;

use OsmScripts\Core\Command;
use OsmScripts\Core\Git;
use OsmScripts\Core\Package;
use OsmScripts\Core\Project;
use OsmScripts\Core\Script;
use OsmScripts\Core\Shell;
use OsmScripts\Core\Variables;
use OsmScripts\Deploy\Hints\DeploymentHint;
use OsmScripts\PhpStorm\PhpStormProject;
use Symfony\Component\Console\Input\InputOption;

/** @noinspection PhpUnused */

/**
 * `push` shell command class. Should run in a project's root directory
 *
 * @property string $path Current directory
 *      in the current directory
 * @property Git $git Git helper
 * @property Shell $shell @required Helper for running commands in local shell
 * @property Variables $variables Helper for managing script variables
 *
 * @property string $packagist_user
 * @property string $packagist_token
 *
 * @property Project $project Composer project in the current directory
 * @property PhpStormProject $phpstorm PhpStorm project
 * @property string[] $paths_under_git
 * @property Package[] $developed_packages
 * @property object|DeploymentHint $deployment
 * @property string[] $allowed_project_branch_patterns
 */
class Push extends Command
{
    #region Properties

    public function default($property) {
        /* @var Script $script */
        global $script;

        switch ($property) {
            // dependencies
            case 'path': return $script->cwd;
            case 'git': return $script->singleton(Git::class);
            case 'shell': return $script->singleton(Shell::class);
            case 'variables': return $script->singleton(Variables::class);

            // command-line arguments and options
            case 'packagist_user': return $this->input->getOption('packagist-user');
            case 'packagist_token': return $this->input->getOption('packagist-token');

            // other
            case 'phpstorm': return new PhpStormProject(['path' => $this->path]);
            case 'project': return new Project(['path' => $this->path]);
            case 'paths_under_git': return $this->getPathsUnderGit();
            case 'developed_packages': return $this->getDevelopedPackages();
            case 'deployment': return $this->variables->get('projects')->{$this->path}
                ?? (object)[];
            case 'allowed_project_branch_patterns':
                return $this->getAllowedProjectBranchPatterns();
        }

        return parent::default($property);
    }

    protected function getPathsUnderGit() {
        if (!$this->phpstorm->vcs) {
            throw new \Exception("'{$this->path}' is not a PhpStorm project or no directory is managed by PhpStorm Version Control");
        }

        return $this->phpstorm->getVcsPaths();
    }

    protected function getDevelopedPackages() {
        $result = [];

        foreach ($this->paths_under_git as $path) {
            if (preg_match('/^vendor\/(?<vendor>[^\/]+)\/(?<package>[^\/]+)$/',
                $path, $match))
            {
                $package = "{$match['vendor']}/{$match['package']}";

                if (!($package_ = $this->project->packages[$package] ?? null)) {
                    throw new \Exception("Install '{$package}' package using Composer before using this command");
                }

                $result[$package] = $package_;
            }
        }

        return $result;
    }


    protected function getAllowedProjectBranchPatterns() {
        $result = $this->deployment->branch_patterns
            ?? ['master', 'v\\d+', 'production'];

        if (!is_array($result)) {
            $result = [$result];
        }

        return $result;
    }

    #endregion

    protected function configure() {
        $this
            ->setDescription('Pushes all developed Git repos in the ' .
                'current project, versions developed packages and updates ' .
                'all target projects on all target servers')
            ->addOption('packagist-user', null, InputOption::VALUE_REQUIRED,
                'Your user name on packagist.org',
                $this->variables->get('packagist_user'))
            ->addOption('packagist-token', null, InputOption::VALUE_REQUIRED,
                "Your API token on packagist.org (click 'Your API token' in your packagist.org profile)",
                $this->variables->get('packagist_token'))
                ;
    }

    protected function handle() {
        $this->verifyPackages();
        $this->verifyProject();

        $this->pushPackages();
    }

    protected function verifyPackages() {
        foreach ($this->developed_packages as $package) {
            $this->shell->cd($package->path, function() use ($package) {
                $this->verifyPackage($package);
            });
        }
    }

    /**
     * Verifies that a package is OK for pushing. Expects that the package's
     * directory is the current directory
     *
     * @param Package $package
     */
    protected function verifyPackage(Package $package) {
        if (!empty($this->git->getUncommittedFiles())) {
            throw new \Exception("Commit pending changes in '{$package->name}' " .
                "Composer package, then run this command again");
        }

        $branch = $this->git->getCurrentBranch();

        if ($major = $this->getMajorVersion($branch)) {
            if (!($latestTag = $this->getLatestTag($major))) {
                throw new \Exception("Package '{$package->name}' " .
                    "doesn't have a single '{$branch}.Y.X' version tag " .
                    "on '{$branch}' branch. Create '{$branch}.0.0' version " .
                    "tag manually, then run this command again");
            }

            foreach ($this->git->getCommitMessagesSince($latestTag) as $message) {
                if (mb_stripos($message, 'major:') === 0) {
                    throw new \Exception("Package '{$package->name}' " .
                        "introduced major (incompatible) changes " .
                        "on '{$branch}' branch since '{$latestTag}' version. " .
                        "Release major changes as a new major version by " ,
                        "creating 'v" . ($major + 1) . "' branch on the " .
                        "first commit marked with 'major:' message, switching " .
                        "to the new branch, manually tagging the latest commit " .
                        "with 'v" . ($major + 1) . ".0.0' version tag, " .
                        "switching to the 'v" . ($major + 1) . "' branch and " .
                        "running this command on that branch");
                }
            }
        }
    }

    protected function verifyProject() {
        if (!is_dir('.git')) {
            return;
        }

        if (!$this->git->config('remote.origin.url') &&
            !$this->git->config('remote.origin.pushurl'))
        {
            return;
        }


        if (!empty($this->git->getUncommittedFiles())) {
            throw new \Exception("Commit pending changes in the project, then run this command again");
        }

        if (!$this->isOnAllowedBranch()) {
            throw new \Exception("This command can only run on " .
                implode(', ', array_map(function($pattern) {
                    return "'{$pattern}'";
                }, $this->allowed_project_branch_patterns)).
                "branches");
        }
    }

    protected function pushPackages() {
        foreach ($this->developed_packages as $package) {
            $this->shell->cd($package->path, function() use ($package) {
                $this->pushPackage($package);
            });
        }
    }

    /**
     * Pushes a package. Expects that the package's directory is the
     * current directory
     *
     * @param Package $package
     * @throws \Exception
     */
    protected function pushPackage(Package $package) {
        $branch = $this->git->getCurrentBranch();

        $this->git->fetch();
        if ($this->git->remoteBranchExists($branch)) {
            $this->git->merge("origin/{$branch}");
        }

        $this->versionPackage($branch);

        $this->git->push($branch);
        $this->git->pushTags();

        $this->updatePackagist($package);
    }

    /**
     * Creates a version tag in the the package located in the current
     * directory based on the current branch (v1, v2, v3) and commit history
     * since the last version tag (checks for "minor:" prefix)
     *
     * @param $branch
     */
    protected function versionPackage($branch) {
        if (!($major = $this->getMajorVersion($branch))) {
            return;
        }

        $tag = $this->getLatestTag($major);
        $messages = $this->git->getCommitMessagesSince($tag);
        if (!count($messages)) {
            return;
        }

        $tag = $this->incrementVersion($tag, $this->hasMinorChanges($messages));

        $this->git->createTag($tag);
    }


    protected function getComposerBranch(Package $package) {
        if (preg_match('/^dev-(?<branch>.+)$/', $package->lock->version,
            $match))
        {
            return $match['branch'];
        }

        if (preg_match('/^(?<branch>v\\d+)\\.x-dev$/', $package->lock->version,
            $match))
        {
            return $match['branch'];
        }

        return null;
    }

    protected function getMajorVersion($branch) {
        if (preg_match('/^v(<version>\\d+)$/', $branch, $match))
        {
            return intval($match['version']);
        }

        return null;
    }

    /**
     * Returns the latest version of the package for a given major version.
     * Expects that the package's directory is the current directory
     *
     * @param $major
     * @return mixed|string|null
     */
    protected function getLatestTag($major) {
        $result = null;

        foreach ($this->git->getTags() as $tag) {
            if (!preg_match('/^v' . preg_quote($major). '\\.\\d+\\.\\d+$/', $tag)) {
                continue;
            }

            if (!$result || version_compare($tag, $result) > 0) {
                $result = $tag;
            }
        }

        return $result;
    }

    protected function hasMinorChanges($messages) {
        foreach ($messages as $message) {
            if (mb_stripos($message, 'minor:') === 0) {
                return true;
            }
        }

        return false;
    }

    protected function incrementVersion($tag, $minor) {
        if (!(preg_match('/^v(?<major>\\d+)\\.(?<minor>\\d+)\\.(?<fix>\\d+)$/',
            $tag, $match)))
        {
            throw new \Exception("'{$tag}' is not a valid version tag");
        }

        return $minor
            ? "v{$match['major']}." . (intval($match['minor']) + 1) . ".0"
            : "v{$match['major']}.{$match['minor']}." . (intval($match['fix']) + 1);
    }

    /**
     * Updates the package on packagist.org if the package is installed from
     * there. Expects that the package's directory is the current directory
     *
     * @param Package $package
     */
    protected function updatePackagist(Package $package) {
        if ($this->isPackageInstalledFromItsGitRepo($package)) {
            return;
        }

        $url = "https://packagist.org/api/update-package" .
            "?username={$this->packagist_user}&apiToken={$this->packagist_token}";
        $context  = stream_context_create([
            'http' => [
                'header'  => "application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode([
                    'repository' => [
                        'url' => "https://packagist.org/packages/{$package->name}",
                    ],
                ]),
            ]
        ]);

        if (file_get_contents($url, false, $context) === false) {
            throw new \Exception("Failed to update '{$package->name}' " .
                "Composer package on packagist.org");
        }
    }

    protected function isPackageInstalledFromItsGitRepo(Package $package) {
        $urls = array_filter([
            $this->git->config('remote.origin.url'),
            $this->git->config('remote.origin.pushurl'),
        ]);

        if (empty($urls)) {
            throw new \Exception("Remote Git repository for " .
                "'{$package->name}' package is not configured");
        }

        foreach ($this->project->json->repositories as $repository) {
            if ($repository->type != 'vcs') {
                continue;
            }

            if (in_array($repository->url, $urls)) {
                return true;
            }
        }

        return false;
    }

    protected function isOnAllowedBranch() {
        $branch = $this->git->getCurrentBranch();
        foreach ($this->allowed_project_branch_patterns as $pattern) {
            if (preg_match("/{$pattern}/", $branch)) {
                return true;
            }
        }

        return false;
    }

}