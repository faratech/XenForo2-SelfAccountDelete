<?php

namespace LiamW\AccountDelete\Repository;

use XF;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

class AccountDelete extends Repository
{
	/**
	 * @return XF\Mvc\Entity\Finder
	 */
	public function findAccountsToRemind()
	{
		$delDelay = $this->options()->liamw_accountdelete_deletion_delay;
		$reminderThreshold = $this->options()->liamw_accountdelete_reminder_threshold ;
		$maxInitDate = (XF::$time - ($delDelay * 86400)) + ($reminderThreshold * 86400);

		$finder = $this->finder('LiamW\AccountDelete:AccountDelete')
			->where('status', 'pending')
			->where('reminder_sent', 0)
			->where('initiation_date', '<=', $maxInitDate);

		if (!$reminderThreshold)
		{
			$finder->whereImpossible();
		}

		return $finder;
	}

	/**
	 * @return XF\Mvc\Entity\Finder
	 */
	public function findAccountsToDelete()
	{
		$maxInitDate = XF::$time - ($this->options()->liamw_accountdelete_deletion_delay * 86400);

		return $this->finder('LiamW\AccountDelete:AccountDelete')
			->where('status', 'pending')
			->where('initiation_date', '<=', $maxInitDate);
	}

	public function getNextRemindTime($deletionDelay = null, $reminderThreshold = null)
	{
		$deletionDelay = $deletionDelay ?: $this->options()->liamw_accountdelete_deletion_delay;
		$reminderThreshold = $reminderThreshold ?: $this->options()->liamw_accountdelete_reminder_threshold;

		if (!$reminderThreshold)
		{
			return null;
		}

		$nextInitiationDate = $this->db()->fetchOne("
			SELECT MIN(initiation_date) FROM xf_liamw_accountdelete_account_deletions WHERE status='pending' AND reminder_sent=0
		");
		return $nextInitiationDate ? ($nextInitiationDate + ($deletionDelay * 86400)) - ($reminderThreshold * 86400) : null;
	}

	public function getNextDeletionTime($deletionDelay = null)
	{
		$deletionDelay = $deletionDelay ?: $this->options()->liamw_accountdelete_deletion_delay;

		$nextInitiationDate = $this->db()->fetchOne("
			SELECT MIN(initiation_date) FROM xf_liamw_accountdelete_account_deletions WHERE status='pending'
		");

		return $nextInitiationDate ? $nextInitiationDate + ($deletionDelay * 86400) : null;
	}

	public function getDeletedUserUsername(User $user)
	{
		$randomName = $this->options()->liamw_accountdelete_randomise_username['username'];
		$randomName = str_replace('{userId}', $user->user_id, $randomName);

		return substr($randomName, 0, $user->getMaxLength('username'));
	}
}