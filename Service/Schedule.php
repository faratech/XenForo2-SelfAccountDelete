<?php

namespace LiamW\AccountDelete\Service;

use XF\App;
use XF\Entity\Forum;
use XF\Entity\User;
use XF\Service\ValidateAndSavableTrait;

class Schedule extends \XF\Service\AbstractService
{
	use ValidateAndSavableTrait;

	/**
	 * @var User|\LiamW\AccountDelete\XF\Entity\User
	 */
	protected $user;

	/**
	 * @var \LiamW\AccountDelete\Entity\AccountDelete
	 */
	protected $accountDeletion;

	protected $sendEmail = true;

	protected $immediateExecution = false;

	/** @var \XF\Service\Thread\Creator */
	protected $threadCreator;

	public function __construct(App $app, User $user)
	{
		parent::__construct($app);

		/** @var \LiamW\AccountDelete\XF\Entity\User $user */

		$this->user = $user;
		$this->accountDeletion = $user->getRelationOrDefault('PendingAccountDeletion');
	}

	public function setSendEmail($sendEmail = true)
	{
		$this->sendEmail = $sendEmail;
	}

	public function setReason($reason)
	{
		$this->accountDeletion->reason = substr($reason, 0, $this->app->options()->liamw_accountdelete_reasonMaxLength);
	}

	public function setImmediateExecution($forced = true)
	{
		$this->immediateExecution = $forced;
	}

	public function sendReportIntoForum(Forum $forum)
	{
		/** @var User $threadStarter */
		$threadStarter = $this->em()->find('XF:User', $this->app->options()->liamw_accountdelete_thread_user);

		\XF::asVisitor($threadStarter ?: $this->user, function () use ($forum) {
			/** @var \XF\Service\Thread\Creator $threadCreator */
			$threadCreator = $this->service('XF:Thread\Creator', $forum);

			$threadCreator->setIsAutomated();
			if ($forum->default_prefix_id)
			{
				$threadCreator->setPrefix($forum->default_prefix_id);
			}

			$options = $this->app->options();

			$title = $options->liamw_accountdelete_thread_title;
			$title = strtr($title, ['{username}' => $this->user->username]);

			$message = $options->liamw_accountdelete_thread_message;
			$message = strtr($message, [
				'{username}' => $this->user->username,
				'{reason}' => $this->accountDeletion->reason,
				'{end_date}' => $this->app->templater()->func('date', [$this->accountDeletion->end_date])
			]);

			$threadCreator->setContent($title, $message);

			$this->threadCreator = $threadCreator;
		});
	}

	public function getThreadCreator()
	{
		return $this->threadCreator;
	}

	protected function _validate()
	{
		if ($this->threadCreator && !$this->threadCreator->validate($errors))
		{
			return $errors;
		}

		$accountDeletion = $this->accountDeletion;

		$accountDeletion->preSave();
		return $accountDeletion->getErrors();
	}

	protected function _save()
	{
		$accountDeletion = $this->accountDeletion;

		if ($this->threadCreator)
		{
			$threadCreator = $this->threadCreator;

			/** @var \XF\Entity\Thread $thread */
			$thread = $threadCreator->save();
			$accountDeletion->set('thread_id', $thread->thread_id, ['forceSet' => true]);

			\XF::asVisitor($this->user, function() use($thread)
			{
				/** @var \XF\Repository\Thread $threadRepo */
				$threadRepo = $this->repository('XF:Thread');
				$threadRepo->markThreadReadByVisitor($thread, $thread->post_date);
			});
		}

		$accountDeletion->save();

		if ($this->immediateExecution && $accountDeletion->end_date <= \XF::$time)
		{
			\XF::runLater(function () use ($accountDeletion) {
				/** @var AccountDelete $deleteService */
				$deleteService = $this->service('LiamW\AccountDelete:AccountDelete', $this->user);
				$deleteService->executeDeletion();
			});
		}
		else
		{
			/** @var \LiamW\AccountDelete\Repository\AccountDelete $accountDeleteRepo */
			$accountDeleteRepo = $this->repository('LiamW\AccountDelete:AccountDelete');

			$nextRemindTime = $accountDeleteRepo->getNextRemindTime();
			if ($nextRemindTime)
			{
				$this->app->jobManager()->enqueueLater(
					'lwAccountDeleteReminder',
					$nextRemindTime,
					'LiamW\AccountDelete:SendDeleteReminders'
				);
			}
			else
			{
				$this->app->jobManager()->cancelUniqueJob('lwAccountDeleteReminder');
			}

			$nextDeletionTime = $accountDeleteRepo->getNextDeletionTime();
			if ($nextDeletionTime)
			{
				$this->app->jobManager()->enqueueLater(
					'lwAccountDeleteRunner',
					$nextDeletionTime,
					'LiamW\AccountDelete:DeleteAccounts'
				);
			}

			if ($this->sendEmail)
			{
				$this->sendScheduledEmail();
			}
		}

		return $accountDeletion;
	}

	protected function sendScheduledEmail()
	{
		if (!$this->user->email || $this->user->user_state != 'valid')
		{
			return;
		}

		$mail = $this->app->mailer()->newMail();
		$mail->setToUser($this->user);
		$mail->setTemplate('liamw_accountdelete_delete_scheduled');
		$mail->send();
	}
}