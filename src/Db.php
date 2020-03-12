<?php

namespace OsmScripts\Deploy;

use OsmScripts\Core\Db as BaseDb;

class Db extends BaseDb
{
    public $version = 1;

    protected function install() {
        parent::install();

        if ($this->installed_version < 1) {
            $this->exec(<<<SQL
                CREATE TABLE version_tags (
                    repo VARCHAR(255) NOT NULL,
                    branch VARCHAR(255) NOT NULL,
                    version VARCHAR(255) NOT NULL,
                )
SQL
            );
            $this->exec(<<<SQL
                CREATE UNIQUE INDEX version_tags_unique 
                    ON version_tags(repo, branch)
SQL
            );
        }
    }

    public function getVersionTag($repo, $branch) {
        return $this->value(<<<SQL
            SELECT version 
            FROM version_tags 
            WHERE repo = :repo AND branch = :branch
SQL
        , ['repo' => $repo, 'branch' => $branch]);
    }

    public function setVersionTag($repo, $branch, $version) {
        $this->exec(<<<SQL
            INSERT INTO version_tags (repo, branch, version)
            VALUES (:repo, :branch, :version)
            ON CONFLICT (repo, branch) DO UPDATE
            SET version = excluded.version
SQL
        , ['repo' => $repo, 'branch' => $branch, 'version' => $version]);
    }
}