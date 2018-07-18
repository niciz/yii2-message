<?php

use yii\db\Migration;

/**
 * Class m180718_084000_change_sitecontent_params_string_to_text
 */
class m180718_084000_change_sitecontent_params_string_to_text extends Migration
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
        echo "m180718_084000_change_sitecontent_params_string_to_text cannot be reverted.\n";

        return false;
    }

    // Use up()/down() to run migration code without a transaction.
    public function up()
    {
        $this->alterColumn('message', 'params', $this->text());
    }
}
