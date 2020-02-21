<?php

namespace OsmScripts\Deploy\Hints;

/**
 * @property string|string[] $branch_patterns Project's branches on which
 *      `deploy push` is allowed. If omitted, `master`, `v\d+` and
 *      `production` branches are allowed
 */
abstract class DeploymentHint
{

}