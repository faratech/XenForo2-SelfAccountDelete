# 2.0.5 Patch Level 6
- XF 2.3 support
- Fixed saving user criteria option on XF 2.3
- Fix: avoid passing null to $runTime param for XF\Job\Manager::enqueueLater()
- Fix: avoid starting and cancelling deletion process by banned users (should not be possible in standard cases, but is possible when using addons that change banned user page behavior)

# 2.0.5:
- New feature: thread report creation about started deletion process
- Added username randomization format option
- Added option to remove avatar & banner on account disable
- Added option to remove profile info & set closed privacy settings
- Added option to disable visitor menu item
