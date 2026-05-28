<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'admin#getConfig',       'url' => '/admin/config',          'verb' => 'GET'],
        ['name' => 'admin#saveConfig',      'url' => '/admin/config',          'verb' => 'POST'],
        ['name' => 'admin#testLdap',        'url' => '/admin/test-ldap',       'verb' => 'POST'],
        ['name' => 'admin#syncAll',         'url' => '/admin/sync-all',        'verb' => 'POST'],
        ['name' => 'admin#applyStorage',    'url' => '/admin/apply-storage',   'verb' => 'POST'],
        ['name' => 'admin#getLdapGroups',   'url' => '/admin/ldap-groups',     'verb' => 'GET'],
    ],
];
