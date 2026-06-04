<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'admin#getConfig',       'url' => '/admin/config',           'verb' => 'GET'],
        ['name' => 'admin#saveConfig',      'url' => '/admin/config',           'verb' => 'POST'],
        ['name' => 'admin#testLdap',        'url' => '/admin/test-ldap',        'verb' => 'POST'],
        ['name' => 'admin#syncAll',         'url' => '/admin/sync-all',         'verb' => 'POST'],
        ['name' => 'admin#applyStorage',    'url' => '/admin/apply-storage',    'verb' => 'POST'],
        ['name' => 'admin#getLdapGroups',   'url' => '/admin/ldap-groups',      'verb' => 'GET'],
        // API DSM Synology
        ['name' => 'admin#testDsmApi',      'url' => '/admin/test-dsm-api',     'verb' => 'POST'],
        ['name' => 'admin#testSmb',          'url' => '/admin/test-smb',         'verb' => 'POST'],
        ['name' => 'admin#discoverAcl',     'url' => '/admin/discover-acl',     'verb' => 'GET'],
        ['name' => 'admin#clearAclCache',   'url' => '/admin/clear-acl-cache',  'verb' => 'POST'],
    ],
];
