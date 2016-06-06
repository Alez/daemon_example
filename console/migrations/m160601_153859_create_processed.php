<?php

use yii\db\Migration;

/**
 * Handles the creation for table `processed`.
 */
class m160601_153859_create_processed extends Migration
{
    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->createTable('processed', [
            'id' => $this->primaryKey(),
            'data' => $this->text(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->dropTable('processed');
    }
}
