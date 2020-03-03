<?php

namespace OsmScripts\Deploy\Config;

use OsmScripts\Core\Object_;

/**
 * @property string[] $push_from Project's branches on which
 *      `deploy push` is allowed. If omitted, `master`, `v\d+` and
 *      `production` branches are allowed
 */
class Project extends Object_
{
    #region Properties

    protected function default($property) {
        switch ($property) {
            case 'push_from': return ['master', 'v\\d+'];
        }

        return parent::default($property);
    }

    #endregion
}