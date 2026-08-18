<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * An append-only record of who changed the authorization model.
 *
 * Everything else about RBAC is reconstructible from the current tables — but
 * only its *current* state. "Who gave this account super_admin, and when" was
 * unanswerable, which is the one question that gets asked after an incident.
 *
 * `actor_id` is nullable and set to NULL when that account is deleted: the
 * record of what happened must outlive the person who did it, so the foreign
 * key cannot cascade. `subject_id` is not a foreign key at all — a deletion is
 * itself an auditable event, and the row has to survive its subject.
 */
class m260819_000003_create_table_rbac_audit extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%rbac_audit}}',
            [
                'id' => $this->primaryKey(),
                'actor_id' => $this->integer()->null()->comment('who made the change; NULL once that account is gone'),
                'subject_id' => $this->integer()->notNull()->comment('the user or role affected'),
                'action' => $this->string(32)->notNull(),
                'detail' => $this->text()->null()->comment('JSON: what changed'),
                'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            ],
            $tableOptions
        );

        $this->createIndex('idx_rbac_audit_subject', '{{%rbac_audit}}', ['subject_id']);
        $this->createIndex('idx_rbac_audit_created', '{{%rbac_audit}}', ['created_at']);

        $this->addForeignKey(
            'fk_rbac_audit_actor',
            '{{%rbac_audit}}',
            ['actor_id'],
            '{{%user}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%rbac_audit}}');
    }
}
