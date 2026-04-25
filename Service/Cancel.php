<?php

namespace LiamW\AccountDelete\Service;

use XF\App;
use XF\Entity\User;
use XF\Service\ValidateAndSavableTrait;

class Cancel extends \XF\Service\AbstractService
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

	/**
	 * @var bool
	 */
	protected $sendEmail;

	protected $forced;

	public function __construct(App $app, \LiamW\AccountDelete\Entity\AccountDelete $accountDeletion)
	{
		parent::__construct($app);

		if (!$accountDeletion->User)
		{
			throw new \LogicException('User does not exists!');
		}

		/** @var \LiamW\AccountDelete\XF\Entity\User $user */
		$this->user = $accountDeletion->User;
		$this->accountDeletion = $accountDeletion;

		$this->accountDeletion->status = 'cancelled';
	}

	public function setForced($forced = true)
	{
		$this->forced = $forced;
	}

	public function setSendEmail($sendEmail = true)
	{
		$this->sendEmail = $sendEmail;
	}

	protected function _validate()
	{
		$accountDeletion = $this->accountDeletion;

		// Do not validate with forced state
		$accountDeletion->preSave();
		return $accountDeletion->getErrors();
	}

	protected function _save()
	{
		$this->accountDeletion->save();

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

		$nextDeleteTime = $accountDeleteRepo->getNextDeletionTime();
		if ($nextDeleteTime)
		{
			$this->app->jobManager()->enqueueLater(
				'lwAccountDeleteRunner',
				$nextDeleteTime,
				'LiamW\AccountDelete:DeleteAccounts'
			);
		}
		else
		{
			$this->app->jobManager()->cancelUniqueJob('lwAccountDeleteRunner');
		}

		if ($this->sendEmail)
		{
			$this->sendCancelledEmail();
		}
	}

	public function sendCancelledEmail()
	{
		if (!$this->user->email || $this->user->user_state != 'valid')
		{
			return;
		}

		$mail = $this->app->mailer()->newMail();
		$mail->setToUser($this->user);
		$mail->setTemplate('liamw_accountdelete_delete_cancelled', ['forced' => $this->forced]);
		$mail->send();
	}
}