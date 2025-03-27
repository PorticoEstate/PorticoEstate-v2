<?php

namespace App\modules\booking\helpers\accounting;

use App\modules\booking\interfaces\AccountingSystemInterface;
use App\modules\phpgwapi\services\Cache;
use Exception;
use GuzzleHttp;

/**
 * Visma Enterprise accounting system integration
 * 
 * Implements the AccountingSystemInterface for Visma Enterprise using
 * their Webservice Voucher API.
 */
class VismaEnterpriseAccounting implements AccountingSystemInterface
{
    private $config;
    private $debug;
    private $lastError;
    
    // Visma Enterprise specific configs
    private $enterprise_url;
    private $enterprise_username;
    private $enterprise_password;
    private $enterprise_company;
    private $enterprise_division;
    private $enterprise_voucher_type;
    private $enterprise_debit_account;
    private $enterprise_credit_account;
    private $enterprise_responsible;
    private $enterprise_accountant;
    private $enterprise_tax_code;
    
    // Advanced configuration
    private $enterprise_dimensions = [];
    private $enterprise_allow_difference = 'false';
    private $enterprise_allow_illegal_account = 'false';
    private $enterprise_check_cs_no = 'false';
    private $enterprise_accumulate_equal_vouchers = 'true';
    
    /**
     * @inheritDoc
     */
    public function __construct($config, $debug = false)
    {
        $this->config = $config;
        $this->debug = $debug;
        $this->lastError = null;
        
        // Initialize Visma Enterprise specific configuration
        $this->enterprise_url = $config['enterprise_url'] ?? '';
        $this->enterprise_username = $config['enterprise_username'] ?? '';
        $this->enterprise_password = $config['enterprise_password'] ?? '';
        $this->enterprise_company = $config['enterprise_company'] ?? '70';
        $this->enterprise_division = $config['enterprise_division'] ?? '0';
        $this->enterprise_voucher_type = $config['enterprise_voucher_type'] ?? '61';
        $this->enterprise_debit_account = $config['enterprise_debit_account'] ?? '1481';
        $this->enterprise_credit_account = $config['enterprise_credit_account'] ?? '2101010';
        $this->enterprise_responsible = $config['enterprise_responsible'] ?? 'Vipps';
        $this->enterprise_accountant = $config['enterprise_accountant'] ?? 'Vipps';
        $this->enterprise_tax_code = $config['enterprise_tax_code'] ?? '0';
        
        // Initialize advanced configuration
        $this->enterprise_dimensions = $config['enterprise_dimensions'] ?? [];
        $this->enterprise_allow_difference = $config['enterprise_allow_difference'] ?? 'false';
        $this->enterprise_allow_illegal_account = $config['enterprise_allow_illegal_account'] ?? 'false';
        $this->enterprise_check_cs_no = $config['enterprise_check_cs_no'] ?? 'false';
        $this->enterprise_accumulate_equal_vouchers = $config['enterprise_accumulate_equal_vouchers'] ?? 'true';
    }
    
    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return !empty($this->enterprise_url) &&
               !empty($this->enterprise_username) &&
               !empty($this->enterprise_password);
    }
    
    /**
     * @inheritDoc
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
    
    /**
     * @inheritDoc
     */
    public function postTransaction($amount, $description, $date, $remote_order_id, $attachment = null, $customer_info = null): bool
    {
        if (!$this->isConfigured())
        {
            $this->lastError = "Visma Enterprise integration is not properly configured";
            if ($this->debug)
            {
                print_r($this->lastError);
            }
            return false;
        }
        
        // Konverter dato til riktig format (YYYY-MM-DD)
        $voucherDate = date('Y-m-d', strtotime($date));
        
        // Hent perioden fra datoen (måneden)
        $period = date('n', strtotime($date));
        
        // Hent året fra datoen
        $year = date('Y', strtotime($date));
        
        // Generer XML for Visma Enterprise Voucher API
        $xml = $this->generateXml(
            $amount,
            $description,
            $voucherDate,
            $period,
            $year,
            $remote_order_id,
            $attachment,
            $customer_info
        );
        
        // Send XML til Visma Enterprise
        return $this->sendToVismaEnterprise($xml);
    }

	public function postRefundTransaction($amount, $description, $date, $remote_order_id, $original_transaction_id = null): bool
	{
		// Formatere refundert beløp (negativt)
		$refundAmount = -1 * abs($amount);
		
		// Dynamisk bytte av debet- og kreditkontoer for å reversere transaksjonen
		$temp = $this->enterprise_debit_account;
		$this->enterprise_debit_account = $this->enterprise_credit_account;
		$this->enterprise_credit_account = $temp;
		
		// Legg til "REFUSJON:" i beskrivelsen
		$refundDescription = "REFUSJON: " . $description;
		
		// Send med referanse til originalbetalingen
		$customer_info = [
			'invoiceNo' => $original_transaction_id ?? $remote_order_id,
			'invoiceDate' => $date
		];
		
		// Bokfør refunderingen med omvendte kontoer
		$result = $this->postTransaction(
			abs($amount), // Beløpet er positivt, men kontoene er byttet
			$refundDescription,
			$date,
			$remote_order_id . '-REFUND',
			null,
			$customer_info
		);
		
		// Sett kontoene tilbake til normal
		$temp = $this->enterprise_debit_account;
		$this->enterprise_debit_account = $this->enterprise_credit_account;
		$this->enterprise_credit_account = $temp;
		
		return $result;
	} 
	   
    /**
     * Generate XML for Visma Enterprise Voucher API
     * 
     * @param float $amount Transaction amount
     * @param string $description Transaction description
     * @param string $voucherDate Date in YYYY-MM-DD format
     * @param int $period Accounting period (month)
     * @param int $year Accounting year
     * @param string $remote_order_id Original payment ID
     * @param array|null $attachment Optional attachment data ['name' => '...', 'content' => '...', 'type' => '...']
     * @param array|null $customer_info Optional customer information for invoice details
     * @return string XML string
     */
    private function generateXml($amount, $description, $voucherDate, $period, $year, $remote_order_id, $attachment = null, $customer_info = null): string
    {
        // Formatere beløpet korrekt med 2 desimaler
        $amount = number_format($amount, 2, '.', '');
        
        // Opprett XML-dokument
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        
        // Opprett rot-element
        $vuxml = $doc->createElement('VUXML');
        $vuxml->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $vuxml->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $vuxml->setAttribute('xsi:noNamespaceSchemaLocation', 'VUacctrans_v1p5.xsd');
        $vuxml->setAttribute('MessageOwner', 'VismaEnterprise');
        $vuxml->setAttribute('MessageType', 'Vouchers');
        $vuxml->setAttribute('MessageVersion', '1.5');
        $doc->appendChild($vuxml);
        
        // Legg til vouchers-element med kontrollattributter
        $vouchers = $doc->createElement('vouchers');
        $vouchers->setAttribute('approveVoucher', 'true');
        $vouchers->setAttribute('updateVoucher', 'true');
        $vouchers->setAttribute('allowDifference', $this->enterprise_allow_difference);
        $vouchers->setAttribute('allowIllegalAccount', $this->enterprise_allow_illegal_account);
        $vouchers->setAttribute('checkCsNo', $this->enterprise_check_cs_no);
        $vouchers->setAttribute('accumulateEqualVouchers', $this->enterprise_accumulate_equal_vouchers);
        $vuxml->appendChild($vouchers);
        
        // Legg til voucher
        $voucher = $doc->createElement('voucher');
        $voucher->setAttribute('company', $this->enterprise_company);
        $voucher->setAttribute('division', $this->enterprise_division);
        $voucher->setAttribute('year', $year);
        $vouchers->appendChild($voucher);
        
        // Legg til voucher-elementer
        $this->appendTextElement($doc, $voucher, 'voucherType', $this->enterprise_voucher_type);
        $this->appendTextElement($doc, $voucher, 'period', $period);
        $this->appendTextElement($doc, $voucher, 'voucherDate', $voucherDate);
        $this->appendTextElement($doc, $voucher, 'responsible', $this->enterprise_responsible);
        $this->appendTextElement($doc, $voucher, 'accountant', $this->enterprise_accountant);
        
        // Legg til textLines
        $textLines = $doc->createElement('textLines');
        $voucher->appendChild($textLines);
        
        $this->appendTextElement($doc, $textLines, 'text', 'Vipps-betaling: ' . $description);
        $this->appendTextElement($doc, $textLines, 'text', 'Ordrenummer: ' . $remote_order_id);
        $this->appendTextElement($doc, $textLines, 'text', 'Bokført: ' . date('Y-m-d H:i:s'));
        
        // Legg til vedlegg hvis tilgjengelig
        if ($attachment !== null && !empty($attachment['content'])) {
            $attachments = $doc->createElement('attachments');
            $voucher->appendChild($attachments);
            
            $attachmentNode = $doc->createElement('attachment');
            $attachments->appendChild($attachmentNode);
            
            $this->appendTextElement($doc, $attachmentNode, 'name', $attachment['name'] ?? 'receipt.pdf');
            $this->appendTextElement($doc, $attachmentNode, 'base64EncodedContent', $attachment['content']);
            $this->appendTextElement($doc, $attachmentNode, 'type', $attachment['type'] ?? 'application/pdf');
        }
        
        // Legg til fakturainformasjon hvis tilgjengelig
        if ($customer_info !== null) {
            $invoice = $doc->createElement('invoice');
            $voucher->appendChild($invoice);
            
            if (isset($customer_info['csNo'])) {
                $this->appendTextElement($doc, $invoice, 'csNo', $customer_info['csNo']);
            }
            
            if (isset($customer_info['name'])) {
                $this->appendTextElement($doc, $invoice, 'csName', $customer_info['name']);
            }
            
            if (isset($customer_info['invoiceNo'])) {
                $this->appendTextElement($doc, $invoice, 'invoiceNo', $customer_info['invoiceNo']);
            }
            
            if (isset($customer_info['invoiceDate'])) {
                $this->appendTextElement($doc, $invoice, 'invoiceDate', $customer_info['invoiceDate']);
            }
            
            if (isset($customer_info['dueDate'])) {
                $this->appendTextElement($doc, $invoice, 'dueDate', $customer_info['dueDate']);
            }
            
            // Legg til adresse hvis tilgjengelig
            if (isset($customer_info['address'])) {
                $address = $doc->createElement('csPostalAddress');
                $invoice->appendChild($address);
                
                if (isset($customer_info['address']['address1'])) {
                    $this->appendTextElement($doc, $address, 'address1', $customer_info['address']['address1']);
                }
                
                if (isset($customer_info['address']['address2'])) {
                    $this->appendTextElement($doc, $address, 'address2', $customer_info['address']['address2']);
                }
                
                if (isset($customer_info['address']['zipCode'])) {
                    $this->appendTextElement($doc, $address, 'zipCode', $customer_info['address']['zipCode']);
                }
                
                if (isset($customer_info['address']['city'])) {
                    $this->appendTextElement($doc, $address, 'city', $customer_info['address']['city']);
                }
                
                if (isset($customer_info['address']['country'])) {
                    $this->appendTextElement($doc, $address, 'country', $customer_info['address']['country']);
                }
            }
            
            // Legg til bankinformasjon hvis tilgjengelig
            if (isset($customer_info['bank'])) {
                $bankInfo = $doc->createElement('bankInformation');
                $invoice->appendChild($bankInfo);
                
                if (isset($customer_info['bank']['kid'])) {
                    $this->appendTextElement($doc, $bankInfo, 'kid', $customer_info['bank']['kid']);
                }
                
                if (isset($customer_info['bank']['accountNo'])) {
                    $this->appendTextElement($doc, $bankInfo, 'accountNo', $customer_info['bank']['accountNo']);
                }
                
                if (isset($customer_info['bank']['iban'])) {
                    $this->appendTextElement($doc, $bankInfo, 'iban', $customer_info['bank']['iban']);
                }
                
                if (isset($customer_info['bank']['swift'])) {
                    $this->appendTextElement($doc, $bankInfo, 'swift', $customer_info['bank']['swift']);
                }
            }
        }
        
        // Debet transaksjon
        $transaction1 = $doc->createElement('transaction');
        $voucher->appendChild($transaction1);
        
        $this->appendTextElement($doc, $transaction1, 'account', $this->enterprise_debit_account);
        $this->appendTextElement($doc, $transaction1, 'voucherText', 'Vipps: ' . $description);
        $this->appendTextElement($doc, $transaction1, 'baseAmount', $amount);
        
        // Legg til valuta
        $currency1 = $doc->createElement('currency');
        $transaction1->appendChild($currency1);
        $this->appendTextElement($doc, $currency1, 'code', 'NOK');
        $this->appendTextElement($doc, $currency1, 'amount', $amount);
        
        $this->appendTextElement($doc, $transaction1, 'taxCode', $this->enterprise_tax_code);
        
        // Legg til dimensjoner for debet
        $this->addDimensions($doc, $transaction1);
        
        // Kredit transaksjon
        $transaction2 = $doc->createElement('transaction');
        $voucher->appendChild($transaction2);
        
        $this->appendTextElement($doc, $transaction2, 'account', $this->enterprise_credit_account);
        $this->appendTextElement($doc, $transaction2, 'voucherText', 'Motpost Vipps: ' . $description);
        $this->appendTextElement($doc, $transaction2, 'baseAmount', '-' . $amount);
        
        // Legg til valuta
        $currency2 = $doc->createElement('currency');
        $transaction2->appendChild($currency2);
        $this->appendTextElement($doc, $currency2, 'code', 'NOK');
        $this->appendTextElement($doc, $currency2, 'amount', '-' . $amount);
        
        $this->appendTextElement($doc, $transaction2, 'taxCode', $this->enterprise_tax_code);
        
        // Legg til dimensjoner for kredit
        $this->addDimensions($doc, $transaction2);
        
        return $doc->saveXML();
    }
    
    /**
     * Add dimensions to a transaction
     * 
     * @param \DOMDocument $doc The XML document
     * @param \DOMNode $transaction The transaction node
     */
    private function addDimensions(\DOMDocument $doc, \DOMNode $transaction): void
    {
        // Legg til dimensjoner for transaksjonen
        foreach ($this->enterprise_dimensions as $dimensionNumber => $dimensionValue) {
            if (!empty($dimensionValue)) {
                $this->appendTextElement($doc, $transaction, 'dimension' . $dimensionNumber, $dimensionValue);
            }
        }
    }
    
    /**
     * Helper method to append a text element to a parent node
     */
    private function appendTextElement(\DOMDocument $doc, \DOMNode $parent, string $name, string $value): void
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($value));
        $parent->appendChild($element);
    }
    
    /**
     * Send XML to Visma Enterprise Voucher API
     * 
     * @param string $xml The XML to send
     * @return bool True if successful, false otherwise
     */
    private function sendToVismaEnterprise($xml): bool
    {
        $url = $this->enterprise_url;

        $headers = [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ];
        
        try
        {
            $client = new GuzzleHttp\Client();
            $requestOptions = [
                'headers' => $headers,
                'body' => $xml,
                'auth' => [$this->enterprise_username, $this->enterprise_password],
                'timeout' => 30,
            ];
            
            $response = $client->request('POST', $url, $requestOptions);
            
            $responseXml = $response->getBody()->getContents();
            
            // Parse XML response for success/error status
            return $this->parseResponse($responseXml);
        }
        catch (\GuzzleHttp\Exception\BadResponseException $e)
        {
            $this->lastError = "API Error: " . $e->getMessage();
            if ($this->debug)
            {
                print_r($this->lastError);
            }
            return false;
        }
        catch (Exception $e)
        {
            $this->lastError = "General Error: " . $e->getMessage();
            if ($this->debug)
            {
                print_r($this->lastError);
            }
            return false;
        }
    }
    
    /**
     * Parse XML response from Visma Enterprise
     * 
     * @param string $xmlString The XML response
     * @return bool True if posting was successful, false otherwise
     */
    private function parseResponse($xmlString): bool
    {
        // Parse XML response
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        
        if ($xml === false)
        {
            $this->lastError = "XML parsing failed";
            if ($this->debug)
            {
                $errors = libxml_get_errors();
                foreach ($errors as $error)
                {
                    print_r("XML Error: {$error->message}\n");
                }
            }
            return false;
        }
        
        // Check for errorFlag attribute
        $errorFlag = (string)$xml->vouchers['errorFlag'];
        
        if ($errorFlag === 'ERROR')
        {
            $this->extractErrorMessages($xml);
            return false;
        }
        
        // Return true for SUCCESS
        return true;
    }
    
    /**
     * Extract error messages from Visma Enterprise response
     * 
     * @param SimpleXMLElement $xml The parsed XML response
     * @return void
     */
    private function extractErrorMessages($xml): void
    {
        $errorMessages = [];
        
        // Extract root level error messages
        if (isset($xml->vouchers->errorMessages->errorMessage))
        {
            foreach ($xml->vouchers->errorMessages->errorMessage as $message)
            {
                $errorMessages[] = (string)$message;
            }
        }
        
        // Extract voucher level error messages
        if (isset($xml->vouchers->voucher))
        {
            foreach ($xml->vouchers->voucher as $voucher)
            {
                if (isset($voucher->errorMessages))
                {
                    foreach ($voucher->errorMessages->errorMessage as $message)
                    {
                        $errorMessages[] = (string)$message;
                    }
                }
                
                // Extract transaction level error messages
                if (isset($voucher->transaction))
                {
                    foreach ($voucher->transaction as $transaction)
                    {
                        if (isset($transaction->errorMessages))
                        {
                            foreach ($transaction->errorMessages->errorMessage as $message)
                            {
                                $errorMessages[] = (string)$message;
                            }
                        }
                    }
                }
            }
        }
        
        if (!empty($errorMessages))
        {
            $this->lastError = "Visma Enterprise Errors: " . implode("; ", $errorMessages);
            if ($this->debug)
            {
                print_r($this->lastError);
            }
        }
        else
        {
            $this->lastError = "Unknown Visma Enterprise error";
        }
    }
}