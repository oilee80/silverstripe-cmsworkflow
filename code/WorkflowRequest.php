<?php
/**
 * A "workflow request" starts a review process for different
 * actions based on a single page.
 * Each request is related to one page.
 * Only one request can exist for each page at any given point in time.
 * Each change of the {@link $Status} property triggers the creation
 * of a new {@link WorkflowRequestChange} object to keep the history of a change request.
 * 
 * @package cmsworkflow
 */
class WorkflowRequest extends DataObject implements i18nEntityProvider {
	
	static $db = array(
		// @todo AwaitingReview
		'Status' => "Enum('AwaitingApproval,Approved,Denied,AwaitingEdit','AwaitingApproval')"
	);
	
	static $has_one = array(
		'Author' => 'Member',
		'Publisher' => 'Member', // see SiteTreeCMSWorkflow->onBeforeWrite()
		'Page' => 'SiteTree'
	);
	
	static $has_many = array(
		'Changes' => 'WorkflowRequestChange', // see WorkflowRequest->onBeforeWrite()
	);
	
	static $many_many = array(
		'Publishers' => 'Member'
	);
	
	/**
	 * @param string $emailtemplate_creation
	 */
	protected static $emailtemplate_awaitingapproval = 'WorkflowGenericEmail';
	
	/**
	 * @param string $emailtemplate_approved
	 */
	protected static $emailtemplate_approved = 'WorkflowGenericEmail';
	
	/**
	 * @param string $emailtemplate_declined
	 */
	protected static $emailtemplate_declined = 'WorkflowGenericEmail';
	
	/**
	 * @param string $emailtemplate_awaitingedit
	 */
	protected static $emailtemplate_awaitingedit = 'WorkflowGenericEmail';
	
	function onBeforeWrite() {
		// if we have a new record, or its status has changed, we track it through a separate relation
		$changedFields = $this->getChangedFields();
		if(!$this->ID || (isset($changedFields['Status']) && $changedFields['Status'])) {
			$change = new WorkflowRequestChange();
			$change->AuthorID = Member::currentUserID();
			$change->Status = $this->Status;
			$page = $this->Page();
			$draftPage = Versioned::get_one_by_stage('SiteTree', 'Draft', "`SiteTree`.`ID` = $page->ID", false, "Created DESC");
			$change->PageDraftVersion = $draftPage->Version;
			$livePage = Versioned::get_one_by_stage('SiteTree', 'Live', "`SiteTree`.`ID` = $page->ID", false, "Created DESC");
			if($livePage) $change->PageLiveVersion = $livePage->Version;
			$change->write();
			$this->Changes()->add($change);
		}
		
		parent::onBeforeWrite();
	}
	
	/**
	 * Notify any publishers assigned to this page when a new request
	 * is lodged.
	 */
	public function notifiyAwaitingApproval() {
		$publishers = $this->Page()->PublisherMembers();
		$author = $this->Author();
		$subject = sprintf(
			_t("{$this->class}.EMAIL_SUBJECT_AWAITINGAPPROVAL"),
			$this->Page()->Title
		);
		$template = $this->stat('emailtemplate_awaitingapproval');
		foreach($publishers as $publisher){
			$this->sendNotificationEmail(
				$author, // sender
				$publisher, // recipient
				$subject,
				$template
			);
		}
	}
	
	/**
	 * Notify the author of a request once a page has been approved (=published).
	 */
	public function notifyApproved() {
		$publisher = Member::currentUser();
		$author = $this->Author();
		$subject = sprintf(
			_t("{$this->class}.EMAIL_SUBJECT_APPROVED"),
			$this->Page()->Title
		);
		$template = self::$emailtemplate_approved;
		$this->sendNotificationEmail(
			$publisher, // sender
			$author, // recipient
			$subject,
			$template
		);
	}
	
	function notifyDeclined() {
		// @todo implement
	}
	
	protected function sendNotificationEmail($sender, $recipient, $subject = null, $template = null) {
		if(!$template) {
			$template = 'WorkflowGenericEmail';
		}
		
		if(!$subject) {
			$subject = sprintf(
				_t('WorkflowRequest.EMAIL_SUBJECT_GENERIC'),
				$this->Page()->Title
			);
		}
		
		$email = new Email();
		$email->setTo($recipient->Email);
		$email->setFrom(($sender->Email) ? $sender->Email : Email::getAdminEmail());
		$email->setTemplate($template);
		$email->setSubject($subject);
		$email->populateTemplate(array(
			"PageCMSLink" => "admin/show/".$this->Page()->ID,
			"Recipient" => $recipient,
			"Sender" => $sender,
			"Page" => $this->Page(),
			"StageSiteLink"	=> $this->Page()->Link()."?stage=stage",
			"LiveSiteLink"	=> $this->Page()->Link()."?stage=live",
			"DiffLink" => $this->diffLink()
		));
		return $email->send();
	}
	
	/**
	 * Is the workflow request still pending.
	 * Important for creation of new workflow requests
	 * as there should be only one open request
	 * per page at any given point in time.
	 * 
	 * @return boolean
	 */
	public function isOpen() {
		return (!in_array($this->Status,array('Approved','Declined')));
	}
	
	/**
	 * Returns a CMS link to see differences made in the request
	 * 
	 * @return string URL
	 */
	protected function diffLink() {
		$page = $this->Page();
		$fromVersion = $page->Version;
		$latestPublished = Versioned::get_one_by_stage($page->class, 'Live', "`SiteTree_Live`.ID = {$page->ID}", true, "Created DESC");
		if(!$latestPublished) return false;
		
		return "admin/compareversions/$page->ID/?From={$fromVersion}&To={$latestPublished->Version}";
	}
	
	/**
	 * Get all publication requests by a specific author
	 * 
	 * @param Member $author
	 * @return DataObjectSet
	 */
	public static function get_by_author($class, $author) {
		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		return DataObject::get(
			"SiteTree", 
			"`Member`.ID = {$author->ID} AND `WorkflowRequest`.ClassName IN ('$classesSQL')", 
			"`SiteTree`.`LastEdited` DESC",
			"LEFT JOIN `WorkflowRequest` ON `WorkflowRequest`.PageID = `SiteTree`.ID " .
			"LEFT JOIN `Member` ON `Member`.ID = `WorkflowRequest`.AuthorID"
		);
	}
	
	/**
	 * Get all publication requests assigned to a specific publisher
	 * 
	 * @param string $class WorkflowRequest subclass
	 * @param Member $publisher
	 * @return DataObjectSet
	 */
	public static function get_by_publisher($class, $publisher) {
		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		return DataObject::get(
			"SiteTree", 
			"`WorkflowRequest_Publishers`.MemberID = {$publisher->ID} AND `WorkflowRequest`.ClassName IN ('$classesSQL')", 
			"`SiteTree`.`LastEdited` DESC",
			"LEFT JOIN `WorkflowRequest` ON `WorkflowRequest`.PageID = `SiteTree`.ID " .
			"LEFT JOIN `WorkflowRequest_Publishers` ON `WorkflowRequest`.ID = `WorkflowRequest_Publishers`.WorkflowRequestID"
		);
	}
	
	/**
	 * @return string Translated $Status property
	 */
	public function i18n_Status() {
		switch($this->Status) {
			case 'Open':
				return _t('SiteTreeCMSWorkflow.STATUS_OPEN', 'Open');
			case 'Approved':
				return _t('SiteTreeCMSWorkflow.STATUS_APPROVED', 'Approved');
			case 'AwaitingApproval':
				return _t('SiteTreeCMSWorkflow.STATUS_AWAITINGAPPROVAL', 'Awaiting Approval');
			case 'AwaitingReview':
				return _t('SiteTreeCMSWorkflow.STATUS_AWAITINGEDIT', 'Awaiting Edit');
			case 'Denied':
				return _t('SiteTreeCMSWorkflow.STATUS_DENIED', 'Denied');
			default:
				return _t('SiteTreeCMSWorkflow.STATUS_UNKNOWN', 'Unknown');
		}
	}
	
	function fieldLabels() {
		$labels = parent::fieldLabels();
		
		$labels['Status'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_STATUS', "Status");
		$labels['Author'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_AUTHOR', "Author");
		$labels['Publisher'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_PUBLISHER', "Publisher");
		$labels['Page'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_PAGE', "Page");
		$labels['Publishers'] = _t('SiteTreeCMSWorkflow.FIELDLABEL_PUBLISHERS', "Publishers");
		
		return $labels;
	}
	
	function provideI18nEntities() {
		$entities = array();
		$entities['WorkflowRequest.EMAIL_SUBJECT_GENERIC'] = array(
			"The workflow status of the \"%s\" page has changed",
			PR_MEDIUM,
			'Email subject with page title'
		);
		
		return $entities;
	}
}
?>