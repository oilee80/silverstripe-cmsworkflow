<?php
/**
 * Report to show pages scheduled to be deleted
 *
 * @package cmsworkflow
 * @subpackage reports
 */
class PagesScheduledForDeletionSideReport extends SideReport {
	function title() {
		return _t('PagesScheduledForDeletionSideReport.TITLE',"Workflow: pages scheduled for deletion");
	}
	function records() {
		if (ClassInfo::exists('Subsite') && isset($this->params['AllSubsites'])) {
			$oldSSFilterState = Subsite::$disable_subsite_filter;
			Subsite::$disable_subsite_filter = true;
		}
		
		$startDate = isset($this->params['StartDate']) ? $this->params['StartDate'] : null;
		$endDate = isset($this->params['EndDate']) ? $this->params['EndDate'] : null;
		if ($startDate && $endDate) {
			$where = "ExpiryDate >= '".Convert::raw2sql($startDate)."' AND ExpiryDate <= '".Convert::raw2sql($endDate)."'";
		} else if ($startDate && !$endDate) {
			$where = "ExpiryDate >= '".Convert::raw2sql($startDate)."'";
		} else if (!$startDate && $endDate) {
			$where = "ExpiryDate <= '".Convert::raw2sql($endDate)."'";
		} else {
			$where = "ExpiryDate >= '".SSDatetime::now()->URLDate()."'";
		}

		$doSet = Versioned::get_by_stage('SiteTree', 'Live', $where, 'ExpiryDate DESC');
		if ($doSet) {
			foreach($doSet as $do) {
				$do->HasBacklinks = $do->BackLinkTracking()->Count() ? ' HAS BLS' : false;
			}
		}
		
		if (ClassInfo::exists('Subsite') && isset($this->params['AllSubsites'])) {
			Subsite::$disable_subsite_filter = $oldSSFilterState;
		}
		
		return $doSet;
	}
	function fieldsToShow() {
		return array(
			"Title" => array(
				"source" => array("NestedTitle", array("2")),
				"link" => true,
			),
			"Requester" => array(
				"prefix" => 'Will be deleted at ',
				"source" => "ExpiryDate",
			),
			"HasBacklinks" => array(
				'source' => 'HasBacklinks'
			)
		);
	}
	function getParameterFields() {
		$fieldset = new FieldSet(
			new DateField('StartDate', 'Start date (YYYY-MM-DD HH:mm:ss)'),
			new DateField('EndDate', 'End date (YYYY-MM-DD HH:mm:ss)')
		);
		if (ClassInfo::exists('Subsite')) $fieldset->push(new CheckboxField('AllSubsites', 'All subsites'));
		return $fieldset;
	}
}
	
