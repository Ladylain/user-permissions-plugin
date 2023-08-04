<?php namespace Ladylain\UserPermissions;

use Exception;
use Event;
use Backend;
use Ladylain\UserPermissions\Models\Permission as PermissionModel;
use Rainlab\User\Models\User as UserModel;
use Rainlab\User\Models\UserGroup as UserGroupModel;
use October\Rain\Exception\ApplicationException;
use Illuminate\Support\Facades\DB as Db;
use BackendAuth;
use Config;

class Plugin extends \System\Classes\PluginBase
{
    /**
     * @var array Plugin dependencies
     */
    public $require = ['RainLab.User'];

    public function pluginDetails()
    {
        return [
            'name' => 'ladylain.userpermissions::lang.plugin.name',
            'description' => 'ladylain.userpermissions::lang.plugin.description',
            'author' => 'JoakimBo',
            'icon' => 'icon-unlock-alt'
        ];
    }

    public function boot()
    {
        $this->registerPermissions();
        $this->extendRainlabUserSideMenu();
        $this->extendUserModel();
        $this->extendUserGroupModel();
        $this->extendUserController();
        $this->extendUserGroupController();
    }

    public function registerPermissions()
    {
        return [
            'ladylain.userpermissions.access_permissions' => [
                'tab'   => 'ladylain.userpermissions::lang.plugin.tab',
                'label' => 'ladylain.userpermissions::lang.plugin.access_permissions'
            ],
            'ladylain.userpermissions.access_user_permissions' => [
                'tab'   => 'ladylain.userpermissions::lang.plugin.tab',
                'label' => 'ladylain.userpermissions::lang.plugin.access_user_permissions'
            ],
            'ladylain.userpermissions.access_group_permissions' => [
                'tab'   => 'ladylain.userpermissions::lang.plugin.tab',
                'label' => 'ladylain.userpermissions::lang.plugin.access_group_permissions'
            ],
        ];
    }

    protected function extendRainlabUserSideMenu()
    {
        Event::listen('backend.menu.extendItems', function($manager) {
            $manager->addSideMenuItems('RainLab.User', 'user', [
                'permissions' => [
                    'label' => 'ladylain.userpermissions::lang.permissions.menu_label',
                    'icon' => 'icon-unlock-alt',
                    'permissions' => ['ladylain.userpermissions.access_permissions'],
                    'url' => Backend::url('ladylain/userpermissions/permissions'),
                ]
            ]);
        });
    }

    protected function extendUserModel()
    {
        function getAllowedPermissions($model) {
            if (!$model->is_activated) {
                return [];
            }
            $groupPermissionsQuery = $model->user_permissions()->where('ladylain_userpermissions_user_permission.permission_state', 2)
            ->join('users_groups', 'ladylain_userpermissions_user_permission.user_id', '=', 'users_groups.user_id')
            ->join('ladylain_userpermissions_group_permission', function ($join) {
                $join->on('users_groups.user_group_id', '=', 'ladylain_userpermissions_group_permission.group_id')
                ->on(
                    'ladylain_userpermissions_group_permission.permission_id',
                    '=',
                    'ladylain_userpermissions_user_permission.permission_id'
                )
                ->where('ladylain_userpermissions_group_permission.permission_state', '=', 1);
            })
            ->join('ladylain_userpermissions_permissions as permissions',
                'ladylain_userpermissions_group_permission.permission_id',
                '=',
                'permissions.id'
            )->select(
                'permissions.id',
                'permissions.code',
                'ladylain_userpermissions_user_permission.user_id',
                'ladylain_userpermissions_user_permission.permission_id',
                'ladylain_userpermissions_user_permission.permission_state',
                'ladylain_userpermissions_user_permission.created_at',
                'ladylain_userpermissions_user_permission.updated_at'
            );
            $permissionsQueryResult = $model->user_permissions()->select('id', 'code')->where('permission_state', 1)->union($groupPermissionsQuery)->get();
            if (!$permissionsQueryResult) {
                $permissionsQueryResult = [];
            } else {
                $permissionsQueryResult = $permissionsQueryResult->toArray();
            }
            return $permissionsQueryResult;
        }

        function hasUserPermission($permissionInput, $column, $allowedPermissions) {
            if (is_array($allowedPermissions) && count($allowedPermissions) > 0) {
                foreach ($allowedPermissions as $permission) {
                    if ($permission[$column] == $permissionInput) {
                        return true;
                    }
                }
            }
            return false;
        }

        function normalizePermissionInput($permissions) {
            if (!is_array($permissions)) {
                $permissions = [$permissions];
            }
            $permissions = array_filter($permissions, function ($element) {
                if (is_string($element) || is_int($element)) {
                    return true;
                }
                return false;
            });
            return $permissions;
        }

        UserModel::extend(function($model)
        {
            $model->belongsToMany['user_permissions'] = [
                'Ladylain\UserPermissions\Models\Permission',
                'table' => 'ladylain_userpermissions_user_permission',
                'key' => 'user_id',
                'otherKey' => 'permission_id',
                'timestamps' => true,
                'pivot' => ['permission_state'],
            ];
            $model->bindEvent('model.afterCreate', function() use ($model) {
                $permissions = PermissionModel::all();
                if ($permissions) {
                    foreach($permissions as $permission) {
                        $model->user_permissions()->attach($permission->id, ['permission_state' => 2]);
                    }
                }
            });
            $model->addDynamicMethod('hasUserPermission', function($permissionsInput, $match = 'all') use ($model) {
                if (!is_string($match) || $match != 'all' && $match != 'one') {
                    throw new ApplicationException('second parameter of hasUserPermission() must be of type string with a value of "all" or "one"!');
                }
                $permissionsInput = normalizePermissionInput($permissionsInput);
                if (is_array($permissionsInput) && count($permissionsInput) > 0) {
                    $result = [];
                    $allowedPermissions = getAllowedPermissions($model);
                    foreach ($permissionsInput as $permissionInput) {
                        if (is_string($permissionInput)) {
                            $result[] = hasUserPermission($permissionInput, 'code', $allowedPermissions);
                        } elseif (is_int($permissionInput)) {
                            $result[] = hasUserPermission($permissionInput, 'id', $allowedPermissions);
                        }
                    }
                    if ($match == 'all') {
                        return !in_array(false, $result);
                    } elseif ($match == 'one') {
                        return in_array(true, $result);
                    }
                }
                return false;
            });
            $model->addDynamicMethod(Config::get('ladylain.userpermissions::hasUserPermissionAlias', 'hasUserPermissionAlias'), function($permissionsInput, $match = 'all') use ($model) {
                return $model->hasUserPermission($permissionsInput, $match);
            });
        });
    }

    protected function extendUserGroupModel()
    {
        UserGroupModel::extend(function($model) {
            $model->belongsToMany['user_permissions'] = ['Ladylain\UserPermissions\Models\Permission',
                'table' => 'ladylain_userpermissions_group_permission',
                'key' => 'group_id',
                'otherKey' => 'permission_id',
                'timestamps' => true,
                'pivot' => ['permission_state'],
            ];
            $model->bindEvent('model.afterCreate', function() use ($model) {
                $permissions = PermissionModel::all();
                if ($permissions) {
                    foreach($permissions as $permission) {
                        $model->user_permissions()->attach($permission->id, ['permission_state' => 0]);
                    }
                }
            });
        });
    }

    protected function extendUserController()
    {
        Event::listen('backend.form.extendFields', function($widget) {
            // Only for the User controller
            if (!$widget->getController() instanceof \RainLab\User\Controllers\Users) {
                return;
            }
            // Only for the User model
            if (!$widget->model instanceof \RainLab\User\Models\User) {
                return;
            }
            // only add field if backend user has access
            if (BackendAuth::getUser()->hasAccess('ladylain.userpermissions.access_user_permissions')) {
                $widget->addTabFields([
                    'user_permissions' => [
                        'tab'   => 'ladylain.userpermissions::lang.permissions.menu_label',
                        'type'    => 'userpermissioneditor',
                        'mode' => 'radio',
                        'context' => ['create','preview','update'],
                    ]
                ]);
            }
        });
    }

    protected function extendUserGroupController()
    {
        Event::listen('backend.form.extendFields', function($widget) {
            // Only for the UserGroup controller
            if (!$widget->getController() instanceof \RainLab\User\Controllers\UserGroups) {
                return;
            }
            // Only for the UserGroup model
            if (!$widget->model instanceof \RainLab\User\Models\UserGroup) {
                return;
            }
            // only add field if backend user has access
            if (BackendAuth::getUser()->hasAccess('ladylain.userpermissions.access_group_permissions')) {
                $widget->addTabFields([
                    'user_permissions' => [
                        'tab'   => 'ladylain.userpermissions::lang.permissions.menu_label',
                        'type'    => 'userpermissioneditor',
                        'mode' => 'checkbox',
                        'context' => ['create','preview','update'],
                    ]
                ]);
            }
        });
    }

    public function registerFormWidgets()
    {
        return [
            'Ladylain\UserPermissions\FormWidgets\UserPermissionEditor' => 'userpermissioneditor',
        ];
    }
}
