<?php

namespace niciz\message\controllers;

use niciz\message\events\MessageEvent;
use niciz\message\models\AllowedContacts;
use niciz\message\models\IgnoreListEntry;
use niciz\message\models\Message;
use niciz\message\models\MessageSearch;
use Yii;
use yii\db\IntegrityException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\helpers\StringHelper;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * MessageController handles all user actions related to the yii2-message module
 */
class MessageController extends Controller
{

    const EVENT_BEFORE_DRAFT = 'event_before_draft';
    const EVENT_AFTER_DRAFT = 'event_after_draft';

    const EVENT_BEFORE_TEMPLATE = 'event_before_template';
    const EVENT_AFTER_TEMPLATE = 'event_after_template';

    const EVENT_BEFORE_SEND = 'event_before_send';
    const EVENT_AFTER_SEND = 'event_after_send';

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => [
                            'inbox', 'drafts', 'templates', 'signature', 'out-of-office',
                            'ignorelist', 'sent', 'compose', 'view', 'delete', 'mark-all-as-read',
                            'check-for-new-messages', 'manage-draft', 'manage-template'],
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Returns the count of unread messages for the currently logged in user
     * as well as the titles of the <limit(default:five)> last messages
     * as json for further handling on e.g. the client side.
     *
     * Useful if you want to implement a automatic notification for new users using
     * the longpoll method (e.g. query every 10 seconds).
     *
     * To ensure the user is not being bugged too often, we only display the
     * "new messages" message once every <newMessagesEverySeconds> per session.
     * This defaults to 3600 (once every hour).
     *
     * @return string
     * @throws \yii\base\InvalidConfigException
     */
    public function actionCheckForNewMessages(int $limit = 5): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $truncateLength = 80;

        $session = Yii::$app->session;

        $key = 'last_check_for_new_messages';
        $last = 'last_response_when_checking_for_new_messages';

        $lastCheck = time();

        if ($session->has($key)) {
            $lastCheck = $session->get($key);
        }

        $conditions = [
            'to' => Yii::$app->user->id,
            'status' => Message::STATUS_UNREAD,
        ];

        $unreadCount = Message::find()
            ->where($conditions)
            ->count();

        $newMessagesEverySeconds = Yii::$app->getModule('message')->newMessagesEverySeconds;
        $timeBygone = time() > $lastCheck + $newMessagesEverySeconds;

        $recentMessages = [];

        foreach (Message::find()
                     ->select(['title', 'created_at'])
                     ->where($conditions)
                     ->limit($limit)
                     ->orderBy('created_at DESC')
                     ->all() as $message) {
            $recentMessages[] = [
                'title' => StringHelper::truncate($message->title, $truncateLength),
                'created_at' => $message->created_at,
            ];
        };

        if ($unreadCount != $session->get($last) || $timeBygone) {
            Yii::$app->session->set($last, $unreadCount);
        }

        Yii::$app->session->set($key, time());

        return [
            'unread_count' => $unreadCount,
            'recent_messages' => $recentMessages,
        ];
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

        return $this->render('inbox', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'users' => Message::userFilter(Yii::$app->user->id),
        ]);
    }

    /**
     * Manage your drafts
     * @return mixed
     */
    public function actionDrafts()
    {
        $searchModel = new MessageSearch();
        $searchModel->from = Yii::$app->user->id;
        $searchModel->draft = true;

        Yii::$app->user->setReturnUrl(['//message/message/drafts']);

        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('drafts', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'users' => $this->recipientsFor(Yii::$app->user->id),
        ]);
    }

    /**
     * Manage your templates
     * @return mixed
     */
    public function actionTemplates()
    {
        $searchModel = new MessageSearch();
        $searchModel->from = Yii::$app->user->id;
        $searchModel->templates = true;

        Yii::$app->user->setReturnUrl(['//message/message/drafts']);

        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('templates', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'users' => $this->recipientsFor(Yii::$app->user->id),
        ]);
    }

    protected function recipientsFor($user_id)
    {
        return ArrayHelper::map(
            Message::find()
                ->where(['from' => Yii::$app->user->id])
                ->select('to')
                ->groupBy('to')
                ->all(), 'to', 'recipient.username');
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

            if ($ignored_users = Yii::$app->request->post('ignored_users')) {
                foreach ($ignored_users as $ignored_user) {
                    $model = Yii::createObject([
                        'class' => IgnoreListEntry::class,
                        'user_id' => Yii::$app->user->id,
                        'blocks_user_id' => $ignored_user,
                        'created_at' => date('Y-m-d G:i:s'),
                    ]);

                    if ($model->save()) {
                        Yii::$app->session->setFlash(
                            'success', Yii::t('message',
                            'The list of ignored users has been saved'));
                    } else {
                        Yii::$app->session->setFlash(
                            'error', Yii::t('message',
                            'The list of ignored users could not be saved'));
                    }
                }
            }
        }

        $users = Message::getPossibleRecipients(Yii::$app->user->id);

        $ignored_users = [];

        foreach (IgnoreListEntry::find()
                     ->select('blocks_user_id')
                     ->where(['user_id' => Yii::$app->user->id])
                     ->asArray()->all() as $ignore) {
            $ignored_users[] = $ignore['blocks_user_id'];
        }

        return $this->render('ignorelist', [
            'users' => $users,
            'ignored_users' => $ignored_users,
        ]);
    }

    /**
     * Lists all Message models where i am the author.
     * @return mixed
     */
    public function actionSent()
    {
        $searchModel = new MessageSearch();
        $searchModel->from = Yii::$app->user->id;
        $searchModel->sent = true;
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        Yii::$app->user->setReturnUrl(['//message/message/sent']);

        return $this->render('sent', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'users' => $this->recipientsFor(Yii::$app->user->id),
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
            'status' => Message::STATUS_UNREAD,
        ])->all() as $message) {
            $message->updateAttributes(['status' => Message::STATUS_READ]);
        }

        Yii::$app->session->setFlash(
            'success', Yii::t('message',
            'All messages in your inbox have been marked as read'));

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

        if ($message->status == Message::STATUS_UNREAD && $message->to == Yii::$app->user->id)
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
        $message = Message::find()
            ->where(['hash' => $hash])
            ->andWhere(['!=', 'status', Message::STATUS_DELETED])
            ->one();

        if (!$message) {
            throw new NotFoundHttpException(Yii::t('message', 'The requested message does not exist.'));
        }

        if (Yii::$app->user->id != $message->to && Yii::$app->user->id != $message->from) {
            throw new ForbiddenHttpException(Yii::t('message', 'You are not allowed to access this message.'));
        }

        return $message;
    }

    /**
     * Compose a new Message.
     *
     * When it is an answers to a message ($answers is set) it will set the status of the original message to 'Answered'.
     * You can set an 'context' to link this message on to an entity inside your application. This should be an
     * id or slug or other identifier.
     *
     * If $to and $add_to_recipient_list is set, the recipient will be added to the allowed contacts list. The sender
     * will also be included in the recipient´s allowed contact list. Use this to allow first contact between users
     * in an application where contacts are limited.
     *
     * If creation is successful, the browser will be redirected to the referrer, or 'inbox' page if not set.
     *
     * When this action is called by an Ajax Request, the view is prepared to return a partial view. This is useful
     * if you want to render the compose form inside a Modal.
     *
     * Depending on the submit button that has been used we probably save the message as a draft
     * instead of sending it directly. (since 0.4.0)
     *
     * When the signature is given by the sender, we preload the signature in the message. (since 0.4.0)
     *
     * When the out-of-office message is set by the recipient, we automatically send the message as answer
     * to the reicpient. (since 0.4.0)
     *
     * @return mixed
     * @throws NotFoundHttpException When the user is not found in the database anymore.
     * @var $context string|null This message is related to an entity accessible through this url
     * @var $add_to_recipient_list bool These users did not yet have contact, add both of them to their contact list
     * @since 0.3.0
     * @see README.md
     * @var $to integer|null The 'recipient' attribute will be prefilled with the user of this id
     * @var $answers string|null This message will be marked as an answer to the message of this hash
     */
    public function actionCompose($to = null, $answers = null, $context = null, $add_to_recipient_list = false)
    {
        $this->trigger(self::EVENT_BEFORE_SEND);

        $draft_hash = Message::generateHash();

        if (Yii::$app->request->isAjax) {
            $this->layout = false;
        }

        if (Message::isUserIgnoredBy($to, Yii::$app->user->id)) {
            return $this->render('you_are_ignored');
        }

        if ($add_to_recipient_list && $to) {
            $this->add_to_recipient_list($to);
        }

        $model = new Message();
        $possible_recipients = Message::getPossibleRecipients(Yii::$app->user->id);

        $request = Yii::$app->request;

        if (!Yii::$app->user->returnUrl) {
            Yii::$app->user->setReturnUrl($request->referrer);
        }

        if ($answers) {
            $origin = Message::find()->where(['hash' => $answers])->one();

            if (!$origin) {
                throw new NotFoundHttpException(
                    Yii::t('message', 'Message to be answered can not be found'));
            }
        }

        if ($request->isPost) {
            $recipients = $request->post('Message')['to'];
            $draft_hash = $request->post('draft-hash');

            if (is_numeric($recipients)) { # Only one recipient given
                $recipients = [$recipients];
            }

            if ($request->post('save-as-draft', false)) {
                $this->saveDraft(Yii::$app->user->id, $request->post('Message'));
            } else if ($request->post('save-as-template', false)) {
                $this->saveTemplate(Yii::$app->user->id, $request->post('Message'));
            } else {
                foreach ($recipients as $recipient_id) {
                    $this->sendMessage($recipient_id, $request->post('Message'), $answers ? $origin : null);
                }
                if ($draft_hash) {
                    $this->cleanupDraft($draft_hash);
                }
            }

            return $request->isAjax ? true : $this->goBack();
        }

        $model = $this->prepareCompose($to, $model, $answers ? $origin : null, $context);

        $caption_attribute = Message::determineUserCaptionAttribute();

        return $this->render('compose', [
            'model' => $model,
            'draft_hash' => $draft_hash,
            'answers' => $answers,
            'origin' => $origin ?? null,
            'context' => $context,
            'dialog' => $request->isAjax,
            'allow_multiple' => true,
            'possible_recipients' => ArrayHelper::map($possible_recipients, 'id', $caption_attribute),
        ]);
    }

    /**
     * We remove the draft when the message has been sent successfully.
     * We delete it from the db completely so we do not clutter up our database.
     * @param string $draft_hash
     * @return bool
     */
    protected function cleanupDraft(string $draft_hash): bool
    {
        $user_id = Yii::$app->user->id;
        $status = Message::STATUS_DRAFT;

        if (Message::deleteAll([
                'hash' => $draft_hash,
                'status' => $status,
                'from' => $user_id
            ]) > 0) {
            return true;
        }
        return false;
    }

    /**
     * @param $to
     * @param Message $model
     * @param null $origin
     * @param null $context
     * @return Message
     */
    protected function prepareCompose($to, Message $model, $origin = null, $context = null): Message
    {
        if (is_numeric($to)) {
            $model->to = [$to];
        }

        if ($context) {
            $model->context = $context;
        }

        if ($origin) {
            $prefix = Yii::$app->getModule('message')->answerPrefix;

            // avoid stacking of prefixes (Re: Re: Re:)
            if (!str_starts_with($origin->title, $prefix)) {
                $model->title = $prefix . $origin->title;
            } else {
                $model->title = $origin->title;
            }

            $model->context = $origin->context;
        }

        if ($signature = Message::getSignature(Yii::$app->user->id)) {
            $model->message = "\n\n" . $signature->message;
        }

        return $model;
    }

    /**
     * Everything is validated, send the message.
     * Also handle possible automatic answers ("out-of-office message") from the recipient back to the sender
     *
     * @param int $recipient_id the user that receives the message
     * @param array $attributes the incoming $_POST data
     * @param $origin provide an message that this message should be the answer to
     * @return bool success state of save()
     */
    protected function sendMessage(int $recipient_id, array $attributes, $origin = null): bool
    {
        $model = new Message();
        $model->attributes = $attributes;
        $model->from = Yii::$app->user->id;
        $model->to = $recipient_id;
        $model->status = Message::STATUS_UNREAD;

        if ($model->save()) {
            if ($origin
                && $origin->to == Yii::$app->user->id
                && $origin->status == Message::STATUS_READ) {
                $origin->updateAttributes(['status' => Message::STATUS_ANSWERED]);

                Yii::$app->session->setFlash('success', Yii::t('message',
                    'The message has been answered.'));
            } else {
                Yii::$app->session->setFlash('success', Yii::t('message',
                    'The message has been sent.'));
            }

            $model->handleOutOfOfficeMessage();

            $event = new MessageEvent;
            $event->postData = Yii::$app->request->post();
            $event->message = $model;
            $this->trigger(self::EVENT_AFTER_SEND, $event);

            return true;
        } else {
            Yii::$app->session->setFlash('danger', Yii::t('message',
                'The message could not be sent: '
                . implode(', ', $model->getErrorSummary(true))));
            return false;
        }
    }

    /**
     * @param $from the user that owns the draft
     * @param $post the incoming $_POST data
     */
    protected function saveDraft(int $from, array $attributes, $hash = null): bool
    {
        $this->trigger(self::EVENT_BEFORE_DRAFT);

        $draft = null;

        if ($hash) {
            $draft = Message::find()->where([
                'hash' => $attributes['hash'],
                'status' => Message::STATUS_DRAFT,
                'from' => $from,
            ])->one();
        }

        if (!$draft) {
            $draft = new Message();
        }

        $draft->attributes = $attributes;
        $draft->status = Message::STATUS_DRAFT;
        $draft->from = Yii::$app->user->id;
        $draft->hash = $hash; # it is not mass-assignable, so we set it here
        if (!$draft->title) {
            $draft->title = Yii::t('message', 'No title given');
        }

        if ($draft->save()) {
            Yii::$app->session->setFlash('success', Yii::t('message',
                'The message has been saved as draft.'));

            $event = new MessageEvent;
            $event->postData = Yii::$app->request->post();
            $event->message = $draft;
            $this->trigger(self::EVENT_AFTER_DRAFT, $event);

            return true;
        } else {
            if (!Yii::$app->request->isAjax) {
                Yii::$app->session->setFlash('danger', Yii::t('message',
                        'The message could not be saved as draft: ')
                    . implode(', ', $draft->getErrorSummary(true)));
            }

            return false;
        }
    }

    /**
     * @param $from the user that owns the template
     * @param $post the incoming $_POST data
     */
    protected function saveTemplate(int $from, array $attributes): bool
    {
        $this->trigger(self::EVENT_BEFORE_DRAFT);

        $model = new Message();
        $model->attributes = $attributes;
        $model->status = Message::STATUS_TEMPLATE;
        $model->from = Yii::$app->user->id;

        if ($model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('message',
                'The message has been saved as template.'));

            $event = new MessageEvent;
            $event->postData = Yii::$app->request->post();
            $event->message = $model;
            $this->trigger(self::EVENT_AFTER_TEMPLATE, $event);

            return true;
        } else {
            Yii::$app->session->setFlash('danger', Yii::t('message',
                    'The message could not be saved as template: ')
                . implode(', ', $model->getErrorSummary(true)));
            return false;
        }
    }

    /**
     * @param $to
     * @throws NotFoundHttpException
     */
    protected function add_to_recipient_list($to)
    {
        $user = new Yii::$app->controller->module->userModelClass;
        if ($recipient = $user::findOne($to)) {
            try {
                $ac = new AllowedContacts();
                $ac->user_id = Yii::$app->user->id;
                $ac->is_allowed_to_write = $to;
                $ac->save();

                $ac = new AllowedContacts();
                $ac->user_id = $to;
                $ac->is_allowed_to_write = Yii::$app->user->id;
                $ac->save();
            } catch (IntegrityException $e) {
                // ignore integrity constraint violation in case users are already connected
            }
        } else throw new NotFoundHttpException();
    }

    /**
     * Handle the signature
     * @return string
     */
    public function actionSignature()
    {
        $signature = Message::getSignature(Yii::$app->user->id);

        if (!$signature) {
            $signature = new Message;
            $signature->title = 'Signature';
            $signature->status = Message::STATUS_SIGNATURE;

            Yii::$app->session->setFlash(
                'success', Yii::t('message',
                'You do not have an signature yet. You can set it here.'));
        }

        if (Yii::$app->request->isPost) {
            $signature->load(Yii::$app->request->post());
            $signature->from = Yii::$app->user->id;
            $signature->save();

            Yii::$app->session->setFlash(
                'success', Yii::t('message',
                'Your signature has been saved.'));
        }

        return $this->render('signature', ['signature' => $signature]);
    }

    /**
     * Handle the Out-of-Office message
     * @return string
     */
    public function actionOutOfOffice()
    {
        $outOfOffice = Message::getOutOfOffice(Yii::$app->user->id);

        if (!$outOfOffice) {
            $outOfOffice = new Message;
            $outOfOffice->title = Yii::t('message',
                'Currently i am not available, but i will respond to your message as soon as i am back again');
            $outOfOffice->status = Message::STATUS_OUT_OF_OFFICE_ACTIVE;

            Yii::$app->session->setFlash(
                'success', Yii::t('message',
                'You do not have an out-of-office message yet. You can set it here.'));
        }

        if (Yii::$app->request->isPost) {
            if (isset($_POST['remove-out-of-office-message'])) {
                $outOfOffice->delete();

                Yii::$app->session->setFlash(
                    'success', Yii::t('message',
                    'Your out-of-office message has been removed.'));
            } else {
                $outOfOffice->load(Yii::$app->request->post());

                // We manually assign the status here because it should
                // not mass-assignable in general:
                $outOfOffice->status = $_POST['Message']['status'];

                $outOfOffice->from = Yii::$app->user->id;
                $outOfOffice->save();

                Yii::$app->session->setFlash(
                    'success', Yii::t('message',
                    'Your out-of-office message has been saved.'));
            }
        }

        return $this->render('out_of_office', ['outOfOffice' => $outOfOffice]);
    }

    /**
     * Manage a specific draft or create a new one
     *
     * The (only) difference between a draft and a template is,
     * that the former gets automatically removed after sending
     *
     * When this action is called via an ajax request, we save the incoming
     * hash as draft
     *
     * @param null $hash the hash of the draft to be managed
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionManageDraft($hash = null)
    {
        $draft = null;

        if ($hash) {
            $draft = Message::find()->where([
                'status' => Message::STATUS_DRAFT,
                'from' => Yii::$app->user->id,
                'hash' => $hash,
            ])->one();
        }

        if (!$draft) {
            $draft = new Message;
            $draft = $this->prepareCompose(null, $draft);
            $draft->status = Message::STATUS_DRAFT;
            $draft->from = Yii::$app->user->id;

        }

        $possible_recipients = Message::getPossibleRecipients(Yii::$app->user->id);

        if (Yii::$app->request->isPost) {
            $draft->load(Yii::$app->request->post());

            if (Yii::$app->request->isAjax) {
                $this->saveDraft(Yii::$app->user->id, $draft->attributes, $hash);
                return true;
            } else if (isset($_POST['save-draft'])) {
                $this->saveDraft(Yii::$app->user->id, $draft->attributes);

                Yii::$app->user->setReturnUrl(['//message/message/drafts']);
            } else if (isset($_POST['send-draft'])) {
                if ($this->sendMessage($draft->to, $draft->attributes, null)) {
                    $draft->delete();
                }
                Yii::$app->user->setReturnUrl(['//message/message/inbox']);
            }

            return $this->goBack();
        }

        return $this->render('draft', [
            'draft' => $draft,
            'possible_recipients' => ArrayHelper::map(
                $possible_recipients, 'id', function ($model) {
                if (method_exists($model, '__toString')) {
                    return $model->__toString();
                }
                return $model->username;
            }),
        ]);
    }

    /**
     * Manage a specific template or create a new one
     *
     * The difference between a draft and a template is,
     * that the former gets automatically removed after sending
     *
     * @param null $hash the hash of the template to be managed
     * @return string|Response
     * @throws NotFoundHttpException
     */
    public function actionManageTemplate($hash = null)
    {
        if ($hash) {
            $template = Message::find()->where([
                'status' => Message::STATUS_TEMPLATE,
                'from' => Yii::$app->user->id,
                'hash' => $hash,
            ])->one();
            if (!$template) {
                throw new NotFoundHttpException();
            }
        } else {
            $template = new Message;
            $template->status = Message::STATUS_TEMPLATE;
            $template->from = Yii::$app->user->id;
        }

        $possible_recipients = Message::getPossibleRecipients(Yii::$app->user->id);

        if (Yii::$app->request->isPost) {
            $template->load(Yii::$app->request->post());

            if (isset($_POST['save-template'])) {
                $this->saveTemplate(Yii::$app->user->id, $template->attributes);

                Yii::$app->user->setReturnUrl(['//message/message/templates']);
            } else if (isset($_POST['send-template'])) {
                $this->sendMessage($template->to, $template->attributes, null);
                Yii::$app->user->setReturnUrl(['//message/message/inbox']);
            }

            return $this->goBack();
        }

        return $this->render('template', [
            'template' => $template,
            'possible_recipients' => ArrayHelper::map($possible_recipients, 'id', 'username'),
        ]);
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

        if (in_array($model->status, [
                Message::STATUS_READ,
                Message::STATUS_UNREAD,
                Message::STATUS_ANSWERED,
            ]) && $model->to != Yii::$app->user->id) {
            throw new ForbiddenHttpException;
        }

        if (in_array($model->status, [
                Message::STATUS_DRAFT,
            ]) && $model->from != Yii::$app->user->id) {
            throw new ForbiddenHttpException;
        }

        $model->delete();

        Yii::$app->session->setFlash(
            'success', Yii::t('message',
            'The message has been deleted.'));

        return $this->redirect(['message/inbox']);
    }
}
