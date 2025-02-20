<?php namespace Ladylain\UserPermissions\Controllers;

use BackendMenu;
use Rainlab\User\Models\User as UserModel;
use Rainlab\User\Models\UserGroup as UserGroupModel;

class Permissions extends \Backend\Classes\Controller
{
    public $requiredPermissions = [
        'ladylain.userpermissions.access_permissions'
    ];

    public $implement = [
        'Backend.Behaviors.FormController',
        'Backend.Behaviors.ListController',
    ];

    public $formConfig = 'form_config.yaml';

    public $listConfig = 'list_config.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('RainLab.User', 'user', 'permissions');
        $this->addCss('/plugins/Ladylain/userpermissions/assets/css/form.css');
    }
}
