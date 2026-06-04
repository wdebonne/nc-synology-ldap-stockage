<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Crée la table de mapping utilisateurs synoldap.
 *
 * Même principe que ldap_user_mapping de user_ldap :
 * une fois qu'un utilisateur a été vu dans l'AD, son UID est stocké ici.
 * userExists() consulte cette table en premier → jamais d'appel LDAP pour les
 * utilisateurs connus, quelle que soit la durée de session ou l'état du cache.
 *
 * C'est ce comportement qui rend user_ldap stable avec NC 33.
 */
class Version3001Date20260604000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('synoldap_users')) {
            $table = $schema->createTable('synoldap_users');

            // UID Nextcloud (= sAMAccountName depuis l'AD)
            $table->addColumn('uid', Types::STRING, [
                'notnull' => true,
                'length'  => 64,
            ]);

            // DN LDAP complet — pour le bind d'authentification
            $table->addColumn('dn', Types::TEXT, [
                'notnull' => true,
            ]);

            // Timestamp de la dernière vérification LDAP réussie
            $table->addColumn('verified_at', Types::INTEGER, [
                'notnull'  => true,
                'default'  => 0,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['uid']);
        }

        return $schema;
    }
}
