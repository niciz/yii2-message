<?php

namespace niciz\message\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * MessageSearch represents the model behind the search form about `app\models\Message`.
 */
class MessageSearch extends Message
{
    public $inbox = false;
    public $sent = false;
    public $draft = false;
    public $templates = false;

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['id', 'from', 'to', 'status'], 'integer'],
            [['hash', 'status', 'title', 'message', 'created_at'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    public function beforeValidate()
    {
        return true;
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = Message::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => ['defaultOrder' => ['created_at' => SORT_DESC]]
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
            'created_at' => $this->created_at,
            'status' => $this->status,
        ]);

        if ($this->draft) {
            $query->andFilterWhere(['status' => [
                Message::STATUS_DRAFT,
            ]]);

            $query->andFilterWhere(['not in', 'status', [
                Message::STATUS_TEMPLATE,
                Message::STATUS_SIGNATURE,
                Message::STATUS_READ,
                Message::STATUS_UNREAD,
            ]]);
        } else if ($this->templates) {
            $query->andFilterWhere(['status' => [
                Message::STATUS_TEMPLATE,
            ]]);

            $query->andFilterWhere(['not in', 'status', [
                Message::STATUS_DRAFT,
                Message::STATUS_SIGNATURE,
                Message::STATUS_READ,
                Message::STATUS_UNREAD,
            ]]);
        } else {
            $query->andFilterWhere(['not in', 'status', [
                Message::STATUS_DRAFT,
                Message::STATUS_TEMPLATE,
                Message::STATUS_SIGNATURE,
            ]]);
        }

        // We dont care if the recipient removed the message.
        // We still see them as "sent". This is crucial !
        if (!$this->sent) {
            $query->andFilterWhere(['not in', 'status', [
                Message::STATUS_DELETED,
            ]]);
        }

        // Never find out-of-office message definitions in any in/outbox:
        $query->andFilterWhere(['not in', 'status', [
            Message::STATUS_OUT_OF_OFFICE_ACTIVE,
            Message::STATUS_OUT_OF_OFFICE_INACTIVE,
        ]]);

        $query->andFilterWhere(['like', 'hash', $this->hash])
            ->andFilterWhere(['like', 'title', $this->title])
            ->andFilterWhere(['like', 'message', $this->message]);

        return $dataProvider;
    }
}
