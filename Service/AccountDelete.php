<?php

namespace LiamW\AccountDelete\Service;

use InvalidArgumentException;
use UnexpectedValueException;
use XF;
use XF\App;
use XF\ControllerPlugin\Login;
use XF\Entity\User;
use XF\Mvc\Controller;
use XF\Service\AbstractService;

class AccountDelete extends AbstractService
{
	/**
	 * @var User|\LiamW\AccountDelete\XF\Entity\User
	 */
	protected $user;
	protected $originalUsername;
	protected $originalEmail;

	/**
	 * @var \LiamW\AccountDelete\Entity\AccountDelete
	 */
	protected $accountDeletion;

	protected $renameTo;
	protected $banEmail;
	protected $removeEmail;
	protected $removeAvatar;
	protected $removeProfileBanner;
	protected $removeProfileInfo;
	protected $closePrivacy;
	protected $addUserGroup;

	protected $sendEmail = true;

	public function __construct(App $app, User $user)
	{
		parent::__construct($app);

		/** @var \LiamW\AccountDelete\XF\Entity\User $user */

		$this->user = $user;
		$this->accountDeletion = $user->PendingAccountDeletion;
		$this->originalUsername = $user->username;
		$this->originalEmail = $user->email;
	}

	public function setRenameTo($name)
	{
		if ($name === $this->user->username)
		{
			$this->renameTo = null;
		}
		else
		{
			$this->renameTo = $name;
		}
	}

	public function setBanEmail($option)
	{
		$this->banEmail = $option;
	}

	public function setRemoveEmail($option)
	{
		$this->removeEmail = $option;
	}

	public function setRemoveAvatar($option)
	{
		$this->removeAvatar = $option;
	}

	public function setRemoveProfileBanner($option)
	{
		$this->removeProfileBanner = $option;
	}

	public function setRemoveProfileInfo($option)
	{
		$this->removeProfileInfo = $option;
	}

	public function setClosePrivacy($option)
	{
		$this->closePrivacy = $option;
	}

	/**
	 * @param int|null $userGroupId
	 */
	public function setAddUserGroup($userGroupId)
	{
		$this->addUserGroup = $userGroupId;
	}

	public function setSendEmail($sendEmail = true)
	{
		$this->sendEmail = $sendEmail;
	}

	public function executeDeletion()
	{
		if (!$this->accountDeletion || $this->accountDeletion->end_date > XF::$time)
		{
			return;
		}

		if (!$this->user->canDeleteSelf())
		{
			/** @var Cancel $cancelService */
			$cancelService = $this->service('LiamW\AccountDelete:Cancel', $this->accountDeletion);
			$cancelService->setForced();
			if (!$cancelService->validate($errors))
			{
				throw new \XF\PrintableException($errors);
			}

			$cancelService->save();
			return;
		}

		$options = $this->app->options();
		$methodOption = $options->liamw_accountdelete_deletion_method;

		$usernameRandom = $options->liamw_accountdelete_randomise_username['enabled'] ?? false;
		if ($usernameRandom)
		{
			/** @var \LiamW\AccountDelete\Repository\AccountDelete $accountDeleteRepo */
			$accountDeleteRepo = $this->repository('LiamW\AccountDelete:AccountDelete');
			$this->setRenameTo($accountDeleteRepo->getDeletedUserUsername($this->user));
		}

		switch ($methodOption['mode'])
		{
			case 'disable':
				$this->setRemoveEmail($methodOption['disable_options']['remove_email'] ?? false);
				$this->setRemoveAvatar($methodOption['disable_options']['remove_avatar'] ?? false);
				$this->setRemoveProfileBanner($methodOption['disable_options']['remove_profile_banner'] ?? false);
				$this->setRemoveProfileInfo($methodOption['disable_options']['remove_profile_info'] ?? false);
				$this->setClosePrivacy($methodOption['disable_options']['change_privacy'] ?? false);
				$this->setBanEmail($methodOption['disable_options']['ban_email'] ?? false);
				$this->setAddUserGroup($methodOption['disable_options']['disabled_group_id'] ?? []);

				$removePassword = $methodOption['disable_options']['remove_password'] ?? false;
				if ($removePassword)
				{
					/** @var \XF\Entity\UserAuth $userAuth */
					$userAuth = $this->user->getRelationOrDefault('Auth');
					$userAuth->setNoPassword();

					/** @var \XF\Entity\UserProfile $userProfile */
					$userProfile = $this->user->getRelationOrDefault('Profile');

					$connectedAccounts = $this->user->ConnectedAccounts;
					if ($connectedAccounts)
					{
						foreach ($connectedAccounts as $connectedAccount)
						{
							$connectedAccount->delete();

							/** @var XF\Entity\ConnectedAccountProvider $provider */
							$provider = $this->em()->find('XF:ConnectedAccountProvider', $connectedAccount->provider);
							if ($provider && $provider->getHandler())
							{
								$storageState = $provider->getHandler()->getStorageState($provider, $this->user);
								$storageState->clearProviderData();
							}

							$profileConnectedAccounts = $userProfile->connected_accounts;
							unset($profileConnectedAccounts[$connectedAccount->provider]);
							$userProfile->connected_accounts = $profileConnectedAccounts;
						}
					}
				}

				$this->doDisable();
				break;
			case 'delete':
				$this->setBanEmail($methodOption['delete_options']['ban_email'] ?? false);

				$this->doDelete();
				break;
			default:
				throw new UnexpectedValueException('Unknown option value encountered during member deletion');
		}

		$this->finaliseDeleteDisable();
	}

	protected function doRename()
	{
		if ($this->renameTo)
		{
			$this->user->setTrusted('username', $this->renameTo);
			$this->user->save();
		}
	}

	protected function doDelete()
	{
		$this->user->setOption('liamw_accountdelete_log_manual', false);
		$this->user->setOption('enqueue_rename_cleanup', false);
		$this->user->setOption('enqueue_delete_cleanup', false);

		$this->doRename();

		$this->user->delete();
	}

	protected function doDisable()
	{
		$this->user->setOption('liamw_accountdelete_log_manual', false);
		$this->user->setOption('enqueue_rename_cleanup', false);

		$this->doRename();

		$this->user->user_state = 'disabled';

		if ($this->addUserGroup)
		{
			$secondaryGroups = $this->user->secondary_group_ids;
			if (!in_array($this->addUserGroup, $secondaryGroups))
			{
				$secondaryGroups[] = $this->addUserGroup;
				$this->user->secondary_group_ids = $secondaryGroups;
			}
		}

		$this->user->save();
	}

	protected function finaliseDeleteDisable()
	{
		if ($this->sendEmail)
		{
			$this->sendCompletedEmail();
		}

		if ($this->removeAvatar && $this->user->avatar_date)
		{
			/** @var \XF\Service\User\Avatar $avatarService */
			$avatarService = $this->service('XF:User\Avatar', $this->user);
			$avatarService->logIp(false);
			$avatarService->deleteAvatar();
		}

		if ($this->removeProfileBanner && $this->user->Profile && $this->user->Profile->banner_date)
		{
			/** @var \XF\Service\User\ProfileBanner $profileBannerService */
			$profileBannerService = $this->service('XF:User\ProfileBanner', $this->user);
			$profileBannerService->logIp(false);
			$profileBannerService->deleteBanner();
		}

		if ($this->removeProfileInfo && $this->user->Profile)
		{
			$userProfile = $this->user->Profile;

			$userProfile->setTrusted('dob_day', '');
			$userProfile->setTrusted('dob_month', '');
			$userProfile->setTrusted('dob_year', '');
			$userProfile->setTrusted('signature', '');
			$userProfile->setTrusted('website', '');
			$userProfile->setTrusted('location', '');
			$userProfile->setTrusted('about', '');

			$fieldSet = $userProfile->custom_fields;
			$fieldDefinition = $fieldSet->getDefinitionSet()->filterEditable($fieldSet, 'admin');
			$customFieldsShown = array_keys($fieldDefinition->getFieldDefinitions());

			if ($customFieldsShown)
			{
				$customFields = $userProfile->custom_fields_;
				$emptyFields = array_map(function () {
					return '';
				}, $customFields);
				$fieldSet->bulkSet($emptyFields, $customFieldsShown, 'admin', true);
			}

			$userProfile->preSave();
			$errors = $userProfile->getErrors();
			foreach ($errors as $key => $error)
			{
				\XF::logError("Account Delete: User {$this->user->user_id} '$key' profile saving error: $error");
			}

			if (!$userProfile->hasErrors())
			{
				$userProfile->saveIfChanged();
			}
		}

		if ($this->closePrivacy && $this->user->Privacy)
		{
			$userPrivacy = $this->user->Privacy;
			$userPrivacy->allow_view_profile = 'none';
			$userPrivacy->allow_post_profile = 'none';
			$userPrivacy->allow_send_personal_conversation = 'none';
			$userPrivacy->allow_view_identities = 'none';
			$userPrivacy->allow_receive_news_feed = 'none';

			$userPrivacy->saveIfChanged();
		}

		// Remove email address after sending the completion email
		if ($this->originalEmail && $this->removeEmail && $this->user->exists())
		{
			// setTrusted bypasses validations, allowing us to sent an empty email
			$this->user->setTrusted('email', '');
			$this->user->save();
		}

		if ($this->originalEmail && $this->banEmail)
		{
			/** @var \XF\Repository\Banning $banRepo */
			$banRepo = $this->repository('XF:Banning');
			if (!$banRepo->isEmailBanned($this->originalEmail, XF::app()->get('bannedEmails')))
			{
				$banRepo->banEmail(
					$this->originalEmail,
					XF::phrase('liamw_accountdelete_automated_ban_user_deleted_self'),
					$this->user
				);
			}
		}

		$this->accountDeletion->completion_date = XF::$time;
		$this->accountDeletion->status = 'complete';
		$this->accountDeletion->save();

		$this->runPostDeleteJobs();
	}

	protected function runPostDeleteJobs()
	{
		$user = $this->user;

		$jobList = [];
		if ($this->renameTo)
		{
			$jobList[] = [
				'XF:UserRenameCleanUp',
				[
					'originalUserId' => $user->user_id,
					'originalUserName' => $this->originalUsername,
					'newUserName' => $this->renameTo
				]
			];
		}

		if (!$user->exists())
		{
			$jobList[] = [
				'XF:UserDeleteCleanUp',
				[
					'userId' => $user->user_id,
					'username' => $this->renameTo
				]
			];
		}

		if ($jobList)
		{
			$this->app->jobManager()->enqueueUnique('selfAccountDeleteCleanup' . $user->user_id, 'XF:Atomic', [
				'execute' => $jobList
			]);
		}
	}

	public function sendReminderEmail()
	{
		if (!$this->user->email || $this->user->user_state != 'valid' || $this->accountDeletion->reminder_sent)
		{
			return;
		}

		XF::db()->beginTransaction();

		$mail = XF::mailer()->newMail();
		$mail->setToUser($this->user);
		$mail->setTemplate('liamw_accountdelete_delete_imminent');
		$mail->queue();

		/** @var \LiamW\AccountDelete\Entity\AccountDelete $pendingDeletion */
		$pendingDeletion = $this->accountDeletion;
		$pendingDeletion->reminder_sent = 1;
		$pendingDeletion->save(true, false);

		XF::db()->commit();
	}

	public function sendCompletedEmail()
	{
		if (!$this->originalEmail)
		{
			return;
		}

		$mail = XF::mailer()->newMail();
		$mail->setTo($this->originalEmail, $this->originalUsername);
		$mail->setLanguage(XF::app()->language($this->user->language_id));
		$mail->setTemplate('liamw_accountdelete_delete_completed', ['time' => XF::$time, 'username' => $this->originalUsername]);
		$mail->send();
	}
}