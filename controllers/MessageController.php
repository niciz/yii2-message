<?php

namespace thyseus\message\controllers;

use thyseus\message\models\IgnoreListEntry;
use thyseus\message\models\Message;
use thyseus\message\models\MessageSearch;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * MessageController implements the CRUD actions for Message model.
 */
class MessageController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['inbox', 'ignorelist', 'sent', 'compose', 'view', 'delete', 'mark-all-as-read', 'check-for-new-messages'],
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /** Simply print the count of unread messages for the currently logged in user.
     * If it is only one unread message, display an link to it.
     * Useful if you want to implement a automatic notification for new users using
     * the longpoll method (e.g. query every 10 seconds) */
    public function actionCheckForNewMessages()
    {
        $conditions = ['to' => Yii::$app->user->id, 'status' => 0];

        $count = Message::find()->where($conditions)->count();

        if ($count == 1) {
            $message = Message::find()->where($conditions)->one();

            if($message)
                echo Html::a($message->title, ['//message/message/view', 'hash' => $message->hash]);
        }
        else
            echo $count;
    }

    /**
     * Lists all Message models where i am the recipient.
     * @return mixed
     */
    public function actionInbox()
    {
        $searchModel = new MessageSearch();
        $searchModel->to = Yii::$app->user->id;
        $searchModel->inbox = true;

        Yii::$app->user->setReturnUrl(['//message/message/inbox']);

        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        $users = ArrayHelper::map(
            Message::find()->where(['to' => Yii::$app->user->id])->groupBy('from')->all(), 'from', 'sender.username');

        return $this->render('inbox', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'users' => $users,
        ]);
    }

    /**
     * Manage the personal ignore list.
     * @return mixed
     */
    public function actionIgnorelist()
    {
        Yii::$app->user->setReturnUrl(['//message/message/ignorelist']);

        if (Yii::$app->request->isPost) {
            IgnoreListEntry::deleteAll(['user_id' => Yii::$app->user->id]);

            if (isset(Yii::$app->request->post()['ignored_users']))
                foreach (Yii::$app->request->post()['ignored_users'] as $ignored_user) {
                    $model = Yii::createObject([
                        'class' => IgnoreListEntry::className(),
                        'user_id' => Yii::$app->user->id,
                        'blocks_user_id' => $ignored_user,
                        'created_at' => date('Y-m-d G:i:s'),
                    ]);

                    if ($model->save())
                        Yii::$app->session->setFlash('success', Yii::t('message', 'The list of ignored users has been saved'));
                    else
                        Yii::$app->session->setFlash('error', Yii::t('message', 'The list of ignored users could not be saved'));
                }
        }

        $user = new Yii::$app->controller->module->userModelClass;
        $users = $user::find()->where(['!=', 'id', Yii::$app->user->id])->all();

        $ignored_users = [];

        foreach (IgnoreListEntry::find()->select('blocks_user_id')->where(['user_id' => Yii::$app->user->id])->asArray()->all() as $ignore)
            $ignored_users[] = $ignore['blocks_user_id'];

        return $this->render('ignorelist', ['users' => $users, 'ignored_users' => $ignored_users]);
    }

    /**
     * Lists all Message models where i am the author.
     * @return mixed
     */
    public function actionSent()
    {
        $searchModel = new MessageSearch();
        $searchModel->from = Yii::$app->user->id;
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        Yii::$app->user->setReturnUrl(['//message/message/sent']);

        return $this->render('sent', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }


    /**
     * Mark all messages as read
     * @param integer $id
     * @return mixed
     */
    public function actionMarkAllAsRead()
    {
        foreach (Message::find()->where([
            'to' => Yii::$app->user->id,
            'status' => Message::STATUS_UNREAD])->all() as $message)
            $message->updateAttributes(['status' => Message::STATUS_READ]);

        return $this->redirect(Yii::$app->request->referrer);
    }

    /**
     * Displays a single Message model.
     * @param integer $id
     * @return mixed
     */
    public function actionView($hash)
    {
        $message = $this->findModel($hash);

        if ($message->status == Message::STATUS_UNREAD)
            $message->updateAttributes(['status' => Message::STATUS_READ]);

        return $this->render('view', [
            'message' => $message
        ]);
    }

    /**
     * Finds the Message model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Message the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($hash)
    {
        $message = Message::find()->where(['hash' => $hash])->one();

        if (!$message)
            throw new NotFoundHttpException(Yii::t('message', 'The requested message does not exist.'));

        if (Yii::$app->user->id != $message->to && Yii::$app->user->id != $message->from)
            throw new ForbiddenHttpException(Yii::t('message', 'You are not allowed to access this message.'));

        return $message;
    }

    /**
     * Compose a new Message.
     * When it is an answers to a message ($answers is set) it will set the status of the original message to
     * 'Answered'.
     * You can set an 'context' to link this message on to an entity inside your application. This should be an
     * id or slug or other identifier.
     * If creation is successful, the browser will be redirected to the 'inbox' page.
     * @return mixed
     */
    public function actionCompose($to = null, $answers = null, $context = null)
    {
        $model = new Message();
        $possible_recipients = Message::getPossibleRecipients(Yii::$app->user->id);

        if (!Yii::$app->user->returnUrl)
            Yii::$app->user->setReturnUrl(Yii::$app->request->referrer);

        if ($answers) {
            $origin = Message::find()->where(['hash' => $answers])->one();

            if (!$origin)
                throw new NotFoundHttpException(Yii::t('message', 'Message to be answered can not be found'));

            if (Message::isUserIgnoredBy($to, Yii::$app->user->id))
                throw new ForbiddenHttpException(Yii::t('message', 'The recipient has added you to the ignore list. You can not send any messages to this person.'));
        }

        if (Yii::$app->request->isPost) {
            foreach (Yii::$app->request->post()['Message']['to'] as $recipient_id) {
                $model = new Message();
                $model->load(Yii::$app->request->post());
                $model->to = $recipient_id;
                $model->save();

                if ($answers) {
                    if ($origin && $origin->to == Yii::$app->user->id && $origin->status == Message::STATUS_READ)
                        $origin->updateAttributes(['status' => Message::STATUS_ANSWERED]);
                }
            }
            return $this->goBack();
        } else {
            if ($to)
                $model->to = [$to];

            if ($context)
                $model->context = $context;

            if ($answers) {
                $prefix = Yii::$app->getModule('message')->answerPrefix;

                // avoid stacking of prefixes (Re: Re: Re:)
                if (substr($origin->title, 0, strlen($prefix)) !== $prefix)
                    $model->title = $prefix . $origin->title;
                else
                    $model->title = $origin->title;
            }

            return $this->render('compose', [
                'model' => $model,
                'answers' => $answers,
                'context' => $context,
                'possible_recipients' => ArrayHelper::map($possible_recipients, 'id', 'username'),
            ]);
        }
    }

    /**
     * Deletes an existing Message model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     */
    public function actionDelete($hash)
    {
        $model = $this->findModel($hash);

        if ($model->to != Yii::$app->user->id)
            throw new ForbiddenHttpException;

        $model->delete();

        return $this->redirect(['inbox']);
    }
}
