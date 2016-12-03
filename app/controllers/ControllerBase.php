<?php

namespace Phosphorum\Controllers;

use Phalcon\Mvc\Controller;
use Phosphorum\Models\Posts;
use Phosphorum\Models\Users;
use Phalcon\Mvc\Model\Resultset\Simple;

/**
 * Class ControllerBase
 *
 * @package Phosphorum\Controllers
 *
 * @property \Phalcon\Cache\BackendInterface viewCache
 * @property \Phalcon\Config config
 * @property \Phosphorum\Utils\Slug slug
 * @property \Phalcon\Avatar\Avatarable gravatar
 * @property \Phalcon\Logger\AdapterInterface logger
 * @property \Phalcon\Breadcrumbs breadcrumbs
 * @property \Phosphorum\Providers\Security\Security $security
 * @property \Phosphorum\ReCaptcha $recaptcha
 */
class ControllerBase extends Controller
{
    const POSTS_IN_PAGE = 40;

    public function onConstruct()
    {
        $lastThreads = $this
            ->modelsManager
            ->createBuilder()
            ->from(['p' => 'Phosphorum\Models\Posts'])
            ->groupBy("p.id")
            ->join('Phosphorum\Models\Categories', "r.id = p.categories_id", 'r')
            ->join('Phosphorum\Models\Users', "u.id = p.users_id", 'u')
            ->columns([
                'p.title as title_post',
                'p.id as id_post',
                'p.slug as slug_post',
                'r.name as name_category',
                'u.name as name_user'
            ])
            ->where('p.deleted = 0')
            ->orderBy('p.created_at DESC')
            ->limit(3)
            ->getQuery()
            ->execute();

        /** @var Simple $lastMember */
        $lastMember = Users::find(['order' => 'created_at DESC', 'limit' => 1, 'columns' => 'login']);

        $login = null;
        if ($lastMember->valid()) {
            $login = $lastMember->getFirst()->login;
        }

        $this->view->setVars([
            'app_name'       => $this->config->get('site')->name,
            'app_version'    => VERSION,
            'threads'        => Posts::count(),
            'last_threads'   => $lastThreads,
            'users'          => Users::count(),
            'users_latest'   => $login,
            'actionName'     => $this->dispatcher->getActionName(),
            'controllerName' => $this->dispatcher->getControllerName(),
        ]);
    }

    /**
     * This initializes the timezone in each request
     */
    public function initialize()
    {
        if ($timezone = $this->session->get('identity-timezone')) {
            date_default_timezone_set($timezone);
        }

        $this->breadcrumbs->add('Home', '/');
        $this->view->setVar('limitPost', self::POSTS_IN_PAGE);
    }

    /**
     * This method prepares the queries to be executed in each list of posts
     * The returned builders are used as base in the search, tagged list and index lists
     *
     * @param bool $joinReply
     * @return array
     */
    protected function prepareQueries($joinReply = false)
    {
        /** @var \Phalcon\Mvc\Model\Query\BuilderInterface $itemBuilder */
        $itemBuilder = $this
            ->modelsManager
            ->createBuilder()
            ->from(['p' => 'Phosphorum\Models\Posts'])
            ->orderBy('p.sticked DESC, p.created_at DESC');

        if ($joinReply) {
            $itemBuilder
                ->groupBy('p.id')
                ->join('Phosphorum\Models\PostsReplies', 'r.posts_id = p.id', 'r');
        }

        $totalBuilder = clone $itemBuilder;

        $itemBuilder
            ->columns(['p.*'])
            ->limit(self::POSTS_IN_PAGE);

        $totalBuilder
            ->columns('COUNT(*) AS count');

        return [$itemBuilder, $totalBuilder];
    }
    /**
     * Validation Google captcha
     *
     * @return boolean
     */
    protected function checkCaptcha()
    {
        if (!$this->recaptcha->isEnabled()) {
            return true;
        }

        if ($this->isUserTrust()) {
            return true;
        }

        if (!$this->request->hasPost('g-recaptcha-response')) {
            $this->flashSession->error('Please confirm that you are not a bot.');
            return false;
        }

        $captcha = $this->recaptcha->getCaptcha();
        $resp = $captcha->verify($this->request->getPost('g-recaptcha-response'), $this->request->getClientAddress());

        if (!$resp->isSuccess()) {
            $this->flashSession->error('Please confirm that you are not a bot.');
            return false;
        }

        return true;
    }

    /**
     *
     * @return boolean
     */
    protected function isUserTrust()
    {
        $karma = $this->session->get('identity-karma');

        if (isset($karma) && $karma > 300) {
            return true;
        }

        return false;
    }
}
