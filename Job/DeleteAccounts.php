<?php

namespace LiamW\AccountDelete\Job;


use XF;
use XF\Job\AbstractJob;

class DeleteAccounts extends AbstractJob
{
	public function run($maxRunTime)
	{
		$startTime = microtime(true);

		/** @var \LiamW\AccountDelete\Repository\AccountDelete $repository */
		$repository = XF::repository('LiamW\AccountDelete:AccountDelete');
		$toDelete = $repository->findAccountsToDelete()->fetch();

		if (!$toDelete->count())
		{
			$nextDeletionTime = $repository->getNextDeletionTime();

			if ($nextDeletionTime)
			{
				$resume = $this->resume();
				$resume->continueDate = $nextDeletionTime;

				return $resume;
			}
			else
			{
				// This job will be queued when an account deletion is initiated.

				return $this->complete();
			}
		}

		/** @var \LiamW\AccountDelete\Entity\AccountDelete $item */
		foreach ($toDelete AS $item)
		{
			if ($item->User && $item->User->exists())
			{
				/** @var \LiamW\AccountDelete\Service\AccountDelete $deleteService */
				$deleteService = XF::service('LiamW\AccountDelete:AccountDelete', $item->User);
				$deleteService->executeDeletion();
			}
			else
			{
				// User has already been deleted, but has not been marked as such... fix that.
				$item->status = 'complete';
				$item->save();
			}

			if (microtime(true) - $startTime >= $maxRunTime)
			{
				break;
			}
		}

		return $this->resume();
	}

	public function getStatusMessage()
	{
		return \XF::phrase('liamw_accountdelete_deleting_accounts...');
	}

	public function canCancel()
	{
		return false;
	}

	public function canTriggerByChoice()
	{
		return true;
	}
}