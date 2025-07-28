<?php

/**
 * Update Columns Application
 * 
 * 注册路由和应用信息
 */
final class UpdateColumnsApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Update Columns');
  }

  public function getShortDescription() {
    return pht('Update task columns based on priority');
  }

  public function getBaseURI() {
    return '/project/updatecolumns/';
  }

  public function getIcon() {
    return 'fa-refresh';
  }

  public function getRoutes() {
    return array(
      '/project/updatecolumns/' => array(
        '(?P<projectID>\d+)/' => 'UpdateColumnsController',
      ),
    );
  }

  public function isPrototype() {
    return false;
  }

  public function shouldAppearInLaunchView() {
    return false;
  }

  public function getApplicationOrder() {
    return 0.1;
  }

  public function getApplicationGroup() {
    return self::GROUP_UTILITIES;
  }

  public function getRequiredApplicationCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }
} 