<?php

namespace App\AudienciaBundle;

use Novosga\Module\BaseModule;

class AudienciaBundle extends BaseModule
{
    public function getKeyName()
    {
        return 'audiencia';
    }

    public function getRoleName()
    {
        return 'ROLE_AUDIENCIA';
    }

    public function getIconName()
    {
        return 'gavel';
    }

    public function getDisplayName()
    {
        return 'module.name';
    }

    public function getHomeRoute()
    {
        return 'audiencia_index';
    }
}
