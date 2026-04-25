<?php


namespace LiamW\AccountDelete\Pub\Controller;


use XF\Pub\Controller\AbstractController;

class AccountDeletion extends AbstractController
{
	public function actionIndex()
	{
		$visitor = \XF::visitor();
		if (!$visitor->hasPermission('general', 'lw_accountdelete_viewLogs'))
		{
			return $this->noPermission();
		}

		$page = $this->filterPage();
		$perPage = 20;

		$accountDeletionsFinder = $this->finder('LiamW\AccountDelete:AccountDelete')
			->setDefaultOrder('initiation_date', 'desc')
			->limitByPage($page, $perPage);

		$accountDeletions = $accountDeletionsFinder->fetch();

		$total = $accountDeletionsFinder->total();
		$this->assertValidPage($page, $perPage, $total, 'account-deletion');

		$viewParams = [
			'accountDeletions' => $accountDeletions,
			'page' => $page,
			'perPage' => $perPage,
			'total' => $total
		];

		return $this->view('LiamW\AccountDelete:AccountDeletion\Listing', 'liamw_accountdelete_user_delete_log', $viewParams);
	}
}
