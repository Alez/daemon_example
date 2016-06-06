<?php

use yii\db\Migration;

/**
 * Handles the creation for table `source`.
 */
class m160601_153247_create_source extends Migration
{
    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->createTable('source', [
            'id' => $this->primaryKey(),
            'name' => $this->string(),
            'age' => $this->integer()->unsigned(),
            'gender' => $this->smallInteger(1)->unsigned(),
            'read_lock' => $this->smallInteger(1)->unsigned()->defaultValue(0)->notNull(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->dropTable('source');
    }
}
