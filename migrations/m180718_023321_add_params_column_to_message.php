<?php

use yii\db\Migration;

/**
 * Class m180718_023321_add_params_column_to_message
 */
class m180718_023321_add_params_column_to_message extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m180718_023321_add_params_column_to_message cannot be reverted.\n";

        return false;
    }

    // Use up()/down() to run migration code without a transaction.
    public function up()
    {
        $this->addColumn('message', 'params', $this->string());
    }
}
