<?php

/**
 * Update Columns Controller
 * 
 * 处理"Update_Columns"按钮点击，根据任务优先级批量更新工作板列位置
 * Optimized Version - 优化版本
 */
final class UpdateColumnsController extends PhabricatorController {

  // 共享服务实例，避免重复创建
  private $update_service = null;

  public function shouldAllowPublic() {
    return false;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $project_id = $request->getURIData('projectID');
    
    // 获取项目对象
    $project = $this->getProjectById($project_id, $viewer);
    if (!$project) {
      return new Aphront404Response();
    }

    // 检查用户权限
    if (!$this->checkProjectPermission($project, $viewer)) {
      return new Aphront403Response();
    }

    // 处理AJAX请求
    if ($this->isAjaxRequest($request) && $request->isFormPost()) {
      return $this->processUpdateRequest($project, $viewer, true);
    }

    // 处理普通表单提交
    if ($request->isFormPost()) {
      return $this->processUpdateRequest($project, $viewer, false);
    }

    // 渲染确认对话框
    return $this->renderConfirmationDialog($project);
  }

  /**
   * 获取项目对象
   */
  private function getProjectById($project_id, $viewer) {
    return id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($project_id))
      ->executeOne();
  }

  /**
   * 检查项目权限
   */
  private function checkProjectPermission($project, $viewer) {
    return PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_VIEW
    );
  }

  /**
   * 检查是否为AJAX请求
   */
  private function isAjaxRequest($request) {
    return $request->isAjax() || 
           $request->getHTTPHeader('X-Requested-With') === 'XMLHttpRequest' ||
           $request->getStr('__ajax__') === '1' ||
           strpos($request->getHTTPHeader('Accept') ?: '', 'application/json') !== false;
  }

  /**
   * 统一的更新处理逻辑
   */
  private function processUpdateRequest($project, $viewer, $is_ajax = false) {
    try {
      // 获取工作板任务
      $board_phid = $project->getPHID();
      $tasks = $this->getBoardTasksOptimized($board_phid, $viewer);
      
      if (empty($tasks)) {
        return $this->createEmptyTasksResponse($project, $is_ajax);
      }

      // 获取服务实例并处理任务
      $service = $this->getUpdateService();
      $results = $service->updateTaskColumns($tasks, $board_phid, $viewer);

      // 创建响应
      return $this->createSuccessResponse($project, $results, count($tasks), $is_ajax);

    } catch (Exception $e) {
      return $this->createErrorResponse($project, $e, $is_ajax);
    }
  }

  /**
   * 获取更新服务实例（单例模式）
   */
  private function getUpdateService() {
    if ($this->update_service === null) {
      $this->update_service = new UpdateColumnsService();
    }
    return $this->update_service;
  }

  /**
   * 创建空任务响应
   */
  private function createEmptyTasksResponse($project, $is_ajax) {
    $message = pht('No tasks found on this workboard.');
    
    if ($is_ajax) {
      return id(new AphrontAjaxResponse())
        ->setContent(array(
          'success' => false,
          'error' => $message,
          'moved_count' => 0,
          'error_count' => 0,
          'total_count' => 0
        ));
    } else {
      return $this->newDialog()
        ->setTitle(pht('No Tasks Found'))
        ->appendChild($message)
        ->addCancelButton('/project/board/' . $project->getID() . '/', pht('OK'));
    }
  }

  /**
   * 创建成功响应
   */
  private function createSuccessResponse($project, $results, $total_count, $is_ajax) {
    $moved_count = $results['moved_count'];
    $error_count = $results['error_count'];
    
    if ($is_ajax) {
      return id(new AphrontAjaxResponse())
        ->setContent(array(
          'success' => true,
          'moved_count' => $moved_count,
          'error_count' => $error_count,
          'total_count' => $total_count,
          'message' => pht(
            'Successfully updated %d of %d tasks based on priority.',
            $moved_count,
            $total_count
          )
        ));
    } else {
      // 对于非AJAX请求，重定向回工作板
      return id(new AphrontRedirectResponse())
        ->setURI('/project/board/' . $project->getID() . '/');
    }
  }

  /**
   * 创建错误响应
   */
  private function createErrorResponse($project, $exception, $is_ajax) {
    $error_message = pht('Failed to update columns: %s', $exception->getMessage());
    phlog('UpdateColumnsController: Error - ' . $exception->getMessage());
    
    if ($is_ajax) {
      return id(new AphrontAjaxResponse())
        ->setContent(array(
          'success' => false,
          'error' => $error_message,
          'error_log_uri' => '/logs/error/' . date('Y-m-d'),
          'moved_count' => 0,
          'error_count' => 1,
          'total_count' => 0
        ));
    } else {
      return $this->newDialog()
        ->setTitle(pht('Update Failed'))
        ->appendChild($error_message)
        ->addCancelButton('/project/board/' . $project->getID() . '/', pht('OK'));
    }
  }

  /**
   * 优化的任务获取逻辑
   */
  private function getBoardTasksOptimized($board_phid, $viewer) {
    // 方法1：通过边查询获取与项目关联的任务（主要方法）
    $edge_query = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array($board_phid))
      ->withEdgeTypes(array(
        PhabricatorProjectProjectHasObjectEdgeType::EDGECONST,
      ));
    $task_phids = $edge_query->execute();
    $task_phids = $edge_query->getDestinationPHIDs(array($board_phid));
    
    // 过滤出任务PHIDs
    $task_phids = array_filter($task_phids, function($phid) {
      return substr($phid, 0, 10) === 'PHID-TASK-';
    });
    
    // 获取任务对象
    if (!empty($task_phids)) {
      return id(new ManiphestTaskQuery())
        ->setViewer($viewer)
        ->withPHIDs($task_phids)
        ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
        ->execute();
    }

    // 备用方法：位置查询
    return $this->getBoardTasksByPosition($board_phid, $viewer);
  }

  /**
   * 通过位置查询获取任务（备用方法）
   */
  private function getBoardTasksByPosition($board_phid, $viewer) {
    $positions = id(new PhabricatorProjectColumnPositionQuery())
      ->setViewer($viewer)
      ->withBoardPHIDs(array($board_phid))
      ->execute();

    if (empty($positions)) {
      return array();
    }

    // 提取任务PHIDs
    $task_phids = array();
    foreach ($positions as $position) {
      $object_phid = $position->getObjectPHID();
      if (substr($object_phid, 0, 10) === 'PHID-TASK-') {
        $task_phids[] = $object_phid;
      }
    }

    if (empty($task_phids)) {
      return array();
    }

    // 获取任务对象
    return id(new ManiphestTaskQuery())
      ->setViewer($viewer)
      ->withPHIDs($task_phids)
      ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
      ->execute();
  }

  private function renderConfirmationDialog($project) {
    // Load the JavaScript resource for AJAX functionality
    $this->requireResource('update-columns-js');

    $dialog = $this->newDialog()
      ->setTitle(pht('Update Task Columns'))
      ->appendChild(
        pht(
          'This will move all tasks on the "%s" workboard to appropriate columns ' .
          'based on their priority levels.',
          $project->getDisplayName()
        )
      )
      ->appendChild(
        phutil_tag('br')
      )
      ->appendChild(
        pht(
          'Tasks will be moved according to the configured priority mapping rules:'
        )
      )
      ->appendChild(
        phutil_tag('ul', array(), array(
          phutil_tag('li', array(), pht('Unbreak Now! (100) → "Unbreak Now!" column')),
          phutil_tag('li', array(), pht('High Priority (90, 80) → "High Priority" column')),
          phutil_tag('li', array(), pht('Normal Priority (50) → "In Progress" column')),
          phutil_tag('li', array(), pht('Low Priority (25, 1-39) → "Low Priority" column')),
          phutil_tag('li', array(), pht('Wishlist (0) → "Wishlist" column')),
        ))
      )
      ->addSubmitButton(pht('Update Columns'))
      ->addCancelButton('/project/board/' . $project->getID() . '/', pht('Cancel'));

    return $dialog;
  }
} 