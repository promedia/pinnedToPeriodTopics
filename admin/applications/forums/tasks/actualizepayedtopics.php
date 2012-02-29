<?php

/**
 * <pre>
 * Invision Power Services
 * IP.Board v3.1.4
 * Task: Actualize payed topics
 * Last Updated: $LastChangedDate: 2012-02-28 10:12:44$
 * </pre>
 *
 * @author 		$Author: Korepashka $
 * @since		28th February 2012
 */
if (!defined('IN_IPB')) {
  print "<h1>Incorrect access</h1>You cannot access this file directly. If you have recently upgraded, make sure you upgraded all the relevant files.";
  exit();
}

class task_item {

  /**
   * Parent task manager class
   *
   * @access	protected
   * @var		object
   */
  protected $class;
  /**
   * This task data
   *
   * @access	protected
   * @var		array
   */
  protected $task = array();
  /**
   * Prevent logging
   *
   * @access	protected
   * @var		boolean
   */
  protected $restrict_log = false;
  /**
   * Registry Object Shortcuts
   */
  protected $registry;
  protected $DB;
  protected $settings;
  protected $request;
  protected $lang;
  protected $member;
  protected $cache;
  protected $trash_forum;

  /**
   * Constructor
   *
   * @access	public
   * @param 	object		ipsRegistry reference
   * @param 	object		Parent task class
   * @param	array 		This task data
   * @return	void
   */
  public function __construct(ipsRegistry $registry, $class, $task) {
    /* Make registry objects */
    $this->registry = $registry;
    $this->DB = $this->registry->DB();
    $this->settings = & $this->registry->fetchSettings();
    $this->request = & $this->registry->fetchRequest();
    $this->lang = $this->registry->getClass('class_localization');
    $this->member = $this->registry->member();
    $this->memberData = & $this->registry->member()->fetchMemberData();
    $this->cache = $this->registry->cache();
    $this->caches = & $this->registry->cache()->fetchCaches();

    $this->class = $class;
    $this->task = $task;
    
    // отстойник
    $this->trash_forum = $this->settings['forum_trash_can_id'];
  }

  /**
   * Run this task
   *
   * @access	public
   * @return	void
   */
  public function runTask() {
    // загружаем лангфайлы
    $this->registry->getClass('class_localization')->loadLanguageFile(array('public_global'), 'core');

    // устанавливаем текущее время и время, за которое нужно уведомлять об окончании срока размещения
    $current_time = strtotime(date('Y-m-d'));
    $notify_time = $this->settings['payed_ntf_days'] ? $this->settings['payed_ntf_days'] * 86400 : 604800;

    $send_ndays = array();
    $send_tomorrow = array();
    $ids_to_close = array();
    $send_closed = array();

    // получаем все платные темы ( не находящиеся в отстойнике) 
    $this->DB->build(array('select' => 'tid, title, starter_id, starter_name, forum_id, payed, payed_to', 'from' => 'topics', 'where' => 'payed = 1 AND forum_id <> '. $this->trash_forum));
    $this->DB->execute();

    // заполняем массивы, для отправки уведомлений
    while ($topic = $this->DB->fetch()) {

      // время до окончания срока размещения
      $time_to_the_end = $topic['payed_to'] - $current_time;

      if ($time_to_the_end <= 0) {

        // срок размещения закончился
        $ids_to_close[] = $topic['tid'];
        $send_closed[] = $topic;
      } elseif ($time_to_the_end <= 86400) {

        // заканчивается завтра
        $send_tomorrow[] = $topic;
      } elseif ($time_to_the_end = $notify_time) {

        // заканчивается через N дней
        $send_ndays[] = $topic;
      }
    }

    // отправляем письма манагеру и пользователям о состоянии коммерческой темы
    // 7 дней.....
    if (count($send_ndays) > 0) {

      foreach ($send_ndays as $to_send) {
 
        // получаем инфу о создателе темы
        $ownerId = $to_send['starter_id'];
        $owner = $this->DB->buildAndFetch(array(
                    'select' => '*',
                    'from' => 'members',
                    'where' => "member_id = {$ownerId}"
                        )
        );

        // формируем письмо
        IPSText::getTextClass('email')->getTemplate("payed_n_days_notification");

        IPSText::getTextClass('email')->buildMessage(array(
            'TID' => $to_send['tid'],
            'TOPIC' => $to_send['title'],
            'PAYED_TO' => date('d.m.Y',$to_send['payed_to'])
                )
        );

        IPSText::getTextClass('email')->to = $owner['email'];
        IPSText::getTextClass('email')->sendMail();

        // письмо манагеру
        if (isset($this->settings['payed_ntf_email']) && !empty($this->settings['payed_ntf_email'])) {

          $managerMail = $this->settings['payed_ntf_email'];

          IPSText::getTextClass('email')->getTemplate("payed_n_days_notification_manager");

          IPSText::getTextClass('email')->subject = sprintf(
                  IPSText::getTextClass('email')->subject, $to_send['title']
          );

          IPSText::getTextClass('email')->buildMessage(array(
              'TID' => $to_send['tid'],
              'TOPIC' => $to_send['title'],
              'STARTER' => $to_send['starter_name'],
              'PAYED_TO' => date('d.m.Y',$to_send['payed_to'])
                  )
          );

          IPSText::getTextClass('email')->to = $managerMail;
          IPSText::getTextClass('email')->sendMail();
        }
      }
    }

    // уже завтра...
    if (count($send_tomorrow) > 0) {

      foreach ($send_tomorrow as $to_send) {

        // получаем инфу о создателе темы
        $ownerId = $to_send['starter_id'];
        $owner = $this->DB->buildAndFetch(array(
                    'select' => '*',
                    'from' => 'members',
                    'where' => "member_id = {$ownerId}"
                        )
        );

        // формируем письмо
        IPSText::getTextClass('email')->getTemplate("payed_tomorrow_notification");

        IPSText::getTextClass('email')->buildMessage(array(
            'TID' => $to_send['tid'],
            'TOPIC' => $to_send['title'],
						'PAYED_TO' => date('d.m.Y',$to_send['payed_to'])
                )
        );

        IPSText::getTextClass('email')->to = $owner['email'];
        IPSText::getTextClass('email')->sendMail();

        // письмо манагеру
        if (isset($this->settings['payed_ntf_email']) && !empty($this->settings['payed_ntf_email'])) {

          $managerMail = $this->settings['payed_ntf_email'];

          IPSText::getTextClass('email')->getTemplate("payed_tomorrow_notification_manager");

					IPSText::getTextClass('email')->subject = sprintf(
                  IPSText::getTextClass('email')->subject, $to_send['title']
          );
					
          IPSText::getTextClass('email')->buildMessage(array(
              'TID' => $to_send['tid'],
              'TOPIC' => $to_send['title'],
              'STARTER' => $to_send['starter_name'],
              'SID' => $to_send['starter_id'],
							'PAYED_TO' => date('d.m.Y',$to_send['payed_to'])
                  )
          );

          IPSText::getTextClass('email')->to = $managerMail;
          IPSText::getTextClass('email')->sendMail();
        }
      }
    }

    // в отстойник
    if (count($send_closed) > 0) {

      $classToLoad = IPSLib::loadLibrary(IPSLib::getAppDir('forums') . '/sources/classes/moderate.php', 'moderatorLibrary', 'forums');
      $this->modLibrary = new $classToLoad($this->registry);
      

      foreach ($send_closed as $to_send) {

        // переносим в отстойник
        $this->modLibrary->topicMove($to_send['tid'], $to_send['forum_id'], $this->trash_forum);

        $this->registry->class_forums->allForums[$this->forum['id']]['_update_deletion'] = 1;
        $this->registry->class_forums->allForums[$this->trash_forum]['_update_deletion'] = 1;

        $this->modLibrary->forumRecount($to_send['forum_id']);
        $this->modLibrary->forumRecount($this->trash_forum);

        // получаем инфу о создателе темы
        $ownerId = $to_send['starter_id'];
        $owner = $this->DB->buildAndFetch(array(
                    'select' => '*',
                    'from' => 'members',
                    'where' => "member_id = {$ownerId}"
                        )
        );

        // формируем письмо
        IPSText::getTextClass('email')->getTemplate("payed_closed_notification");

        IPSText::getTextClass('email')->buildMessage(array(
            'TID' => $to_send['tid'],
            'TOPIC' => $to_send['title']
                )
        );

        IPSText::getTextClass('email')->to = $owner['email'];
        IPSText::getTextClass('email')->sendMail();

        // письмо манагеру
        if (isset($this->settings['payed_ntf_email']) && !empty($this->settings['payed_ntf_email'])) {

          $managerMail = $this->settings['payed_ntf_email'];

          IPSText::getTextClass('email')->getTemplate("payed_closed_notification_manager");

					IPSText::getTextClass('email')->subject = sprintf(
                  IPSText::getTextClass('email')->subject, $to_send['title']
          );
					
          IPSText::getTextClass('email')->buildMessage(array(
              'TID' => $to_send['tid'],
              'TOPIC' => $to_send['title'],
              'STARTER' => $to_send['starter_name'],
              'SID' => $to_send['starter_id']
                  )
          );

          IPSText::getTextClass('email')->to = $managerMail;
          IPSText::getTextClass('email')->sendMail();
        }
      }
      $ids_to_close = implode(',', $ids_to_close);
      $this->class->appendTaskLog($this->task, sprintf($this->lang->words['actualize_payed_topics_log'], $ids_to_close));
    }

		
		$this->class->appendTaskLog($this->task, $this->lang->words['actualize_payed_topics_start_log']);
		
    //-----------------------------------------
    // Unlock Task: DO NOT MODIFY!
    //-----------------------------------------

    $this->class->unlockTask($this->task);
  }

}