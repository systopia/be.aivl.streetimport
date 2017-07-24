<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: M. McAndrew (michaelmcandrew@thirdsectordesign.org)  |
|         B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Abstract class bundle common GP importer functions
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_DDRecordHandler extends CRM_Streetimport_GP_Handler_GPRecordHandler {

  /** file name pattern as used by TM company */
  protected static $DD_PATTERN = '#(?P<org>[a-zA-Z\-]+)_Spender_(?P<agency>\w+)_(?P<date>\d{8})[.](csv|CSV)$#';

  /** stores the parsed file name */
  protected $file_name_data = 'not parsed';

  /** stores the parsed file name */
  protected $_dialoger_cache = array();

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @return true or false
   */
  public function canProcessRecord($record, $sourceURI) {
    if ($this->file_name_data === 'not parsed') {
      $this->file_name_data = $this->parseDdFile($sourceURI);
    }
    return $this->file_name_data != NULL;
  }

  /**
   * process the given record
   *
   * @param $record  an array of key=>value pairs
   * @return true
   * @throws exception if failed
   */
  public function processRecord($record, $sourceURI) {
    $config = CRM_Streetimport_Config::singleton();

    // STEP 1: match/create contact
    $contact_id = $this->processContact($record);

    // STEP 2: create a new contract for the contact
    $contract_id = $this->createDDContract($contact_id, $record);

    // STEP 3: process Features and stuff
    $this->processAdditionalInformation($contact_id, $contract_id, $record);

    // STEP 4: create 'manual check' activity
    $note = trim(CRM_Utils_Array::value('Bemerkungen', $record, ''));
    if ($note) {
      $this->createManualUpdateActivity($contact_id, $note, $record);
    }

    $deprecated_start_date = trim(CRM_Utils_Array::value('Vertrags_Beginn', $record, ''));
    if ($deprecated_start_date) {
      $this->createManualUpdateActivity($contact_id, "Deprecated value 'Vertrags_Beginn' given: {$deprecated_start_date}", $record);
    }

    $this->logger->logImport($record, true, $config->translate('DD Contact'));
  }


  /**
   * Create/Find the contact and make sure no base data is lost
   */
  protected function processContact($record) {
    $config = CRM_Streetimport_Config::singleton();

    // compile contact data
    $contact_data = array(
      'formal_title'   => CRM_Utils_Array::value('Titel', $record),
      'first_name'     => CRM_Utils_Array::value('Vorname', $record),
      'last_name'      => CRM_Utils_Array::value('Nachname', $record),
      'prefix_id'      => CRM_Utils_Array::value('Anrede', $record),
      'birth_date'     => CRM_Utils_Array::value('Geburtsdatum', $record),
      'postal_code'    => CRM_Utils_Array::value('PLZ', $record),
      'city'           => CRM_Utils_Array::value('Ort', $record),
      'street_address' => trim(CRM_Utils_Array::value('Straße', $record, '') . ' ' . CRM_Utils_Array::value('HNR', $record, '')),
      'email'          => CRM_Utils_Array::value('Email', $record),
    );

    // set default country
    if (empty($contact_data['country_id'])) {
      $contact_data['country_id'] = $config->getDefaultCountryId();
    }

    // postprocess contact data
    $this->resolveFields($contact_data, $record);

    // and match using XCM
    $contact_match = civicrm_api3('Contact', 'getorcreate', $contact_data);
    $contact_id = $contact_match['id'];

    // make sure the extra fields are stored
    // TODO: deal with office@dialogdirect.at ?
    if (!empty(CRM_Utils_Array::value('Email', $record))) {
      $this->addDetail($record, $contact_id, 'Email', array('email' => CRM_Utils_Array::value('Email', $record)));
    }

    // ...including the phones
    if (!empty(CRM_Utils_Array::value('Telefon', $record))) {
      $phone = '+' . CRM_Utils_Array::value('Telefon', $record);
      $this->addDetail($record, $contact_id, 'Phone', array('phone' => $phone), FALSE, array('phone_type_id' => 1));
    }
    if (!empty(CRM_Utils_Array::value('Mobilnummer', $record))) {
      $phone = '+' . CRM_Utils_Array::value('Mobilnummer', $record);
      $this->addDetail($record, $contact_id, 'Phone', array('phone' => $phone), FALSE, array('phone_type_id' => 2));
    }

    return $contact_id;
  }

  /**
   * Process the additional information for the contact:
   *  groups, tags, contact restrictions, etc.
   */
  protected function processAdditionalInformation($contact_id, $contract_id, $record) {
    $config = CRM_Streetimport_Config::singleton();
    $acceptedYesValues = $config->getAcceptedYesValues();

    // process 'Informationen_elektronisch' => 'Zusendungen nur online'
    if (in_array(CRM_Utils_Array::value('Informationen_elektronisch', $record), $acceptedYesValues)) {
      $this->addContactToGroup($contact_id, $config->getGPGroupID('Zusendungen nur online'), $record);
    }

    // process 'Informationen_elektronisch' => 'Zusendungen nur online'
    if (in_array(CRM_Utils_Array::value('Nicht_Kontaktieren', $record), $acceptedYesValues)) {
      // contact should be set to inactive
      civicrm_api3('Contact', 'setinactive', array(
        'contact_id' => $contact_id));
    }

    // process 'Interesse1' => DD groups
    $interesse_1 = CRM_Utils_Array::value('Interesse1', $record);
    switch ($interesse_1) {
      case '':
        break;

      case 'A':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Aktivisten'), $record);
        break;

      case 'B':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Tierfreunde'), $record);
        break;

      case 'C':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Rationalisten'), $record);
        break;

      case 'D':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Ökobürger'), $record);
        break;

      default:
        $this->logger->logError("Unknown Interesse1 '{$interesse_1}'. Ignored.", $record);
        break;
    }

    // process 'Interesse2' => Interest Groups
    $interesse_2 = CRM_Utils_Array::value('Interesse2', $record);
    switch ($interesse_2) {
      case '':
        break;

      case 'I_MEERE':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Meere'), $record);
        break;

      case 'I_ARKTIS':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Klima/Arktis'), $record);
        break;

      case 'I_ENERGIE':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Atom/Kohle/Erneuerbare'), $record);
        break;

      case 'I_KONSUM':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Konsum/Marktcheck'), $record);
        break;

      case 'I_LANDW':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Landwirtschaft (aka Gentech)'), $record);
        break;

      case 'I_TOXIC':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Toxics'), $record);
        break;

      case 'I_WALD':
        $this->addContactToGroup($contact_id, $config->getGPGroupID('Wald'), $record);
        break;

      // TODO: extend?

      default:
        $this->logger->logError("Unknown Interesse2 '{$interesse_2}'. Ignored.", $record);
        break;
    }

    for ($feature_idx = 1; $feature_idx <= 4; $feature_idx++) {
      $feature = CRM_Utils_Array::value("Leistung{$feature_idx}", $record);

      // if empty do nothing
      if (empty($feature)) continue;

      // process T-Shirt weborders
      if (preg_match('#^(?P<shirt_type>M|W)/(?P<shirt_size>[A-Z]{1,2})$#', $feature, $match)) {
        // create a webshop activity (Activity type: ID 75)  with the status "scheduled"
        //  and in the field "order_type" option value 11 "T-Shirt"
        $this->createWebshopActivity($contact_id, $record, array(
          'subject' => "order type T-Shirt {$match['shirt_type']}/{$match['shirt_size']} AND number of items 1",
          $config->getGPCustomFieldKey('order_type')        => 11, // T-Shirt
          $config->getGPCustomFieldKey('order_count')       => 1,  // 1 x T-Shirt
          $config->getGPCustomFieldKey('shirt_type')        => $match['shirt_type'],
          $config->getGPCustomFieldKey('shirt_size')        => $match['shirt_size'],
          $config->getGPCustomFieldKey('linked_membership') => $contract_id,
          ));
        continue;
      }

      // TODO: more features (Leistungen)?


      // finally: if nothing matched create an error
      $this->logger->logError("Unknown feature (Leistung): '{$feature}'. Ignored.", $record);
    }
  }

  /**
   * Create a new contract for this contact
   */
  protected function createDDContract($contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();

    //  ---------------------------------------------
    // |           CREATE MANDATE                    |
    //  ---------------------------------------------
    $mandate_data = array(
      'iban'               => CRM_Utils_Array::value('IBAN', $record),
      'bic'                => CRM_Utils_Array::value('BIC', $record),
      'start_date'         => date('YmdHis', strtotime(CRM_Utils_Array::value('Vertrags_Beginn', $record, 'now'))),
      'amount'             => CRM_Utils_Array::value('MG_Beitrag_pro_Jahr', $record),
      'frequency_unit'     => 'month',
      'contact_id'         => $contact_id,
      'financial_type_id'  => 3, // Membership Dues
      'currency'           => 'EUR',
      'type'               => 'RCUR',
      'campaign_id'        => $this->getCampaignID($record),
    );

    // process/adjust data:
    //  - calculate amount/frequency
    $frequency = $this->getFrequency($record);
    $mandate_data['frequency_interval'] = 12 / $frequency;
    $amount = number_format($mandate_data['amount'] / $frequency, 2, '.', '');
    if ($amount * $frequency != $mandate_data['amount']) {
      // this is a bad contract amount for the interval
      $frequency = CRM_Utils_Array::value('Vertrags_Beginn', $record);
      $this->logger->logError("Contract annual amount '{$mandate_data['amount']}' not divisiable by frequency {$frequency}.", $record);
    }
    $mandate_data['amount'] = $amount;

    //  - parse start date
    $mandate_data['start_date'] = date('YmdHis', strtotime($mandate_data['start_date']));
    $mandate_data['cycle_day']  = $config->getNextCycleDay($mandate_data['start_date']);

    // create mandate
    $mandate = civicrm_api3('SepaMandate', 'createfull', $mandate_data);
    $mandate = civicrm_api3('SepaMandate', 'getsingle', array('id' => $mandate['id']));


    //  ---------------------------------------------
    // |           CREATE MEMBERSHIP                 |
    //  ---------------------------------------------
    $contract_data = array(
      'contact_id'                                           => $contact_id,
      'membership_type_id'                                   => $this->getMembershipTypeID($record),
      'start_date'                                           => $this->getDate($record),
      'membership_general.membership_channel'                => CRM_Utils_Array::value('Kontaktart', $record),
      'membership_general.membership_contract'               => CRM_Utils_Array::value('MG_NR_Formular', $record),
      'membership_general.membership_dialoger'               => $this->getDialogerID($record),
      'membership_payment.membership_recurring_contribution' => $mandate['entity_id'],
      'membership_payment.from_ba'                           => CRM_Contract_BankingLogic::getOrCreateBankAccount($contact_id, $record['IBAN'], $record['BIC']),
      'membership_payment.to_ba'                             => CRM_Contract_BankingLogic::getCreditorBankAccount(),
    );

    // process/adjust data:
    $contract_data['start_date'] = date('YmdHis', strtotime($contract_data['start_date']));

    // create
    $membership = civicrm_api3('Contract', 'create', $contract_data);
    return $membership['id'];
  }


  //  ---------------------------------------------
  // |          Helper / Lookup Functions          |
  //  ---------------------------------------------

  /**
   * Look up the "dialoger" data based on 'Ausweis_nr'
   */
  protected function getDialogerID($record) {
    $dialoger_id = CRM_Utils_Array::value('Ausweis_nr', $record);
    if (empty($dialoger_id)) {
      return '';
    }

    if (!array_key_exists($dialoger_id, $this->_dialoger_cache)) {
      $config = CRM_Streetimport_Config::singleton();
      $dialoger_id_field = $config->getGPCustomFieldKey('dialoger_id');
      $dialoger = civicrm_api3('Contact', 'get', array(
        $dialoger_id_field  => $dialoger_id,
        'contact_sub_type'  => 'Dialoger',
        'return'            => 'id'));
      if (empty($dialoger['id'])) {
        $this->logger->logError("Dialoger '{$dialoger_id}' not found!", $record);
        $this->_dialoger_cache[$dialoger_id] = '';
      } else {
        $this->_dialoger_cache[$dialoger_id] = $dialoger['id'];
      }
    }

    return $this->_dialoger_cache[$dialoger_id];
  }

  /**
   * derive the membership type id from the 'Vertragstyp' field
   * @todo get value range
   */
  protected function getMembershipTypeID($record) {
    $value = CRM_Utils_Array::value('Vertragstyp', $record);
    $membership_type_name = 'Förderer';
    switch (strtolower($value)) {
      case 'KoenigWald':
        $membership_type_name = 'Könige der Wälder';
        break;

      case 'Foerderer':
      case 'Förderer':
      case 'foerderer':
        $membership_type_name = 'Förderer';
        break;

      case 'Flottenpatenschaft':
      case 'Flottenpate':
      case 'flottenpate':
        $membership_type_name = 'Flottenpatenschaft';
        break;

      case 'Könige der Wälder':
      case 'koenigwald':
        $membership_type_name = 'Könige der Wälder';
        break;

      case 'Landwirtschaft':
      case 'LW':
        $membership_type_name = 'Landwirtschaft';
        break;

      case 'Baumpatenschaft':
        $membership_type_name = 'Baumpatenschaft';
        break;

      case 'arctic defender':
        $membership_type_name = 'arctic defender';
        break;

      case 'Eisbärpatenschaft':
        $membership_type_name = 'Eisbärpatenschaft';
        break;

      case 'Walpatenschaft':
        $membership_type_name = 'Walpatenschaft';
        break;

      case 'Atom-Eingreiftrupp':
      case 'atom_eingreif':
        $membership_type_name = 'Atom-Eingreiftrupp';
        break;

      case 'Greenpeace for me':
      case 'greenpeace_for_me':
        $membership_type_name = 'Greenpeace for me';
        break;

      default:
        $this->logger->logError("Unknown Vertragstyp '{$value}', assuming 'Förderer'.", $record);
        break;
    }

    // find a membership type with that name
    $membership_types = $this->getMembershipTypes();
    foreach ($membership_types as $membership_type) {
      if ($membership_type['name'] == $membership_type_name) {
        return $membership_type['id'];
      }
    }

    // not found?
    $this->logger->logError("Unknown Membership type '{$membership_type_name}'.", $record);
    return 1;
  }

  /**
   * Extracts the specific activity date for this line
   */
  protected function getDate($record) {
    $raw_date = CRM_Utils_Array::value('Aufnahmedatum', $record);
    if (empty($raw_date)) {
      return date('YmdHis');
    } else {
      return date('YmdHis', strtotime($raw_date));
    }
  }

  /**
   * get the frequency from the 'Zahlungszeitraum' plain text value
   * @todo get value range
   */
  protected function getFrequency($record) {
    $value = CRM_Utils_Array::value('Zahlungszeitraum', $record);
    switch (strtolower($value)) {
      case 'monatlich':
        return 12;

      case 'jährlich':
      case 'jahrlich':
        return 1;

      case 'halbjährlich':
      case 'halbjahrlich':
        return 2;

      case 'vierteljährlich':
      case 'vierteljahrlich':
        return 4;

      default:
        $this->logger->logError("Unknown Zahlungszeitraum '{$value}', assuming annual collection.", $record);
        return 1;
        break;
    }
  }

  /**
   * Will try to parse the given name and extract the parameters outlined in TM_PATTERN
   *
   * @return NULL if not matched, data else
   */
  protected function parseDdFile($sourceID) {
    if (preg_match(self::$DD_PATTERN, $sourceID, $matches)) {
      return $matches;
    } else {
      return NULL;
    }
  }

  /**
   * Checks if this record uses IMB or CiviCRM IDs
   */
  protected function getCampaignID($record) {
    switch ($this->file_name_data['agency']) {
      case 'GP':
        return $this->getCampaignIDbyExternalIdentifier('AKTION-7874');
        break;

      case 'DDI':
        return $this->getCampaignIDbyExternalIdentifier('AKTION-7875');
        break;

      case 'WS':
        return $this->getCampaignIDbyExternalIdentifier('AKTION-7876');
        break;

      default:
        $this->logger->logFatal("Unknown agency '{$this->file_name_data['agency']}'. Processing stopped.", $record);
        return;
    }
  }
}