<?php

/**
 * Hvordan overføre filer til agresseo: ekempel fra 'property'
 * Prosedyre:	1) Lag filnavn
 * 				2) Produser filen
 * 				3) Lagre filen lokalt (for referanse/historikk/feilbehandling)
 * 				4) Overfør filen til Agresso
 * 				5) Evnt logg til databasen hvordan dette gikk
 *
 * Forutsetning:1) configurasjon for lokal lagring (katalog)
 * 				2) configurasjon for pålogging til ftp-server (IP/Login/Passord/envt katalog)
 *
 */


use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\FilesystemException;
use App\Database\Db;

class export_agresso
{

	var $db, $config;
	function __construct()
	{
		$this->db = Db::getInstance();
		$this->config = CreateObject('phpgwapi.config', 'booking');
		$this->config->read_repository();
	}

	public function transfer_customer_list($buffer, $filnavn_base)
	{
		$file_written = false;

		$fil_katalog = rtrim($this->config->config_data['invoice_export_path'], "/");

		if (!$fil_katalog)
		{
			$fil_katalog = sys_get_temp_dir();
		}

		$filnavn = $fil_katalog . "/{$filnavn_base}";

		$fp = fopen($filnavn, "wb");
		fwrite($fp, $buffer);

		if (fclose($fp))
		{
			$file_written = true;
		}

		$transfer_ok = false;
		if ($file_written)
		{
			if ($this->config->config_data['invoice_export_method'] == 'ftp')
			{
				$transfer_ok = $this->transfer($filnavn);
			}
			else if ($this->config->config_data['invoice_export_method'] == 'ftps')
			{
				$transfer_ok = $this->transfer_ftps($filnavn);
			}
			else
			{
				$transfer_ok = true;
			}
		}

		if ($transfer_ok)
		{
			$message = "Overfort fil: {$filnavn}";
		}
		else
		{
			$message = 'Noe gikk galt med overforing av godkjendte fakturaer!';
		}
		return $message;
	}

	public function do_your_magic($buffer, $id, $file_name, $extension)
	{
		// Viktig: må kunne rulle tilbake dersom noe feiler.
		$this->db->transaction_begin();

		$filnavn = $this->lagfilnavn($file_name, $extension);

		$file_written = false;

		$fp = fopen($filnavn, "wb");
		fwrite($fp, $buffer);

		if (fclose($fp))
		{
			$file_written = true;
		}

		$transfer_ok = false;
		if ($file_written)
		{
			if ($this->config->config_data['invoice_export_method'] == 'ftp')
			{
				$transfer_ok = $this->transfer($filnavn);
			}
			else if ($this->config->config_data['invoice_export_method'] == 'ftps')
			{
				$transfer_ok = $this->transfer_ftps($filnavn);
			}
			else
			{
				$transfer_ok = true;
			}
		}

		if ($transfer_ok)
		{
			$this->db->transaction_commit();
			$this->config->config_data['invoice_last_id'] = $id;
			$this->config->save_repository();
			$message = "Overfort fil: {$filnavn}";
		}
		else
		{
			$this->db->transaction_abort();
			$message = 'Noe gikk galt med overforing av godkjendte fakturaer!';
		}
		return $message;
	}

	protected function lagfilnavn($file_name_part, $extension = 'TXT')
	{
		$fil_katalog = rtrim($this->config->config_data['invoice_export_path'], "/");
		if (!$fil_katalog)
		{
			$fil_katalog = sys_get_temp_dir();
		}

		$filnavn = $fil_katalog . "/{$file_name_part}_" . date("ymd") . ".{$extension}";
		return $filnavn;

		/*
			$continue = true;
			$i = 1;
			do
			{
				$filnavn = $fil_katalog . "/{$file_name_part}_" . date("ymd") . '_' . sprintf("%02s", $i) . ".{$extension}";

				//Sjekk om filen eksisterer
				If (!file_exists($filnavn))
				{
					return $filnavn;
				}

				$i++;
			}
			while ($continue);

			//Ingen løpenr er ledige, gi feilmelding
			return false;
 * 
 */
	}

	private function transfer_ftps($filnavn)
	{
		$content = file_get_contents($filnavn);
		$basedir = rtrim($this->config->config_data['invoice_ftp_basedir'], '/');
		if ($basedir)
		{
			$newfile = $basedir . '/' . basename($filnavn);
		}
		else
		{
			$newfile = basename($filnavn);
		}
		$host_info		 = explode(':', $this->config->config_data['invoice_ftp_host']);

		$host	 = $host_info[0];
		$port	 = isset($host_info[1]) && $host_info[1] ? $host_info[1] : 22;

		//			$host = $this->config->config_data['invoice_ftp_host'];
		$user = $this->config->config_data['invoice_ftp_user'];
		$pass = $this->config->config_data['invoice_ftp_password'];

		$filesystem = new Filesystem(new SftpAdapter(
			new SftpConnectionProvider(
				$host, // host (required)
				$user, // username (required)
				$pass, // password (optional, default: null) set to null if privateKey is used
				null, // private key (optional, default: null) can be used instead of password, set to null if password is set
				null, // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
				$port, // port (optional, default: 22)
				false, // use agent (optional, default: false)
				10, // timeout (optional, default: 10)
				40, // max tries (optional, default: 4)
				null, // host fingerprint (optional, default: null),
				null, // connectivity checker (must be an implementation of 'League\Flysystem\PhpseclibV2\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
			),
			$basedir, // root path (required)
			PortableVisibilityConverter::fromArray([
				'file' => [
					'public' => 0640,
					'private' => 0604,
				],
				'dir' => [
					'public' => 0740,
					'private' => 7604,
				],
			])
		));

		try
		{
			$filesystem->write(basename($newfile), $content);
			$transfer_ok = true;
		}
		catch (FilesystemException $exception)
		{
			$transfer_ok = false;
		}
		return $transfer_ok;
	}

	protected function transfer($filnavn)
	{

		if ($this->config->config_data['invoice_export_method'] == 'ftp')
		{

			$transfer_ok = false;
			$ftp = $this->phpftp_connect();
			$basedir = $this->config->config_data['invoice_ftp_basedir'];


			if ($basedir)
			{
				$newfile = $basedir . '/' . basename($filnavn);
			}
			else
			{
				$newfile = basename($filnavn);
			}

			if (ftp_put($ftp, $newfile, $filnavn, FTP_BINARY))
			{
				//log_transaction_ok
				$transfer_ok = True;
			}
			else
			{
				//log_transaction_feil
				$transfer_ok = false;
				unlink($filnavn);
			}
			ftp_quit($ftp);
		}
		return $transfer_ok;
	}

	protected function phpftp_connect()
	{
		$host = $this->config->config_data['invoice_ftp_host'];
		$user = $this->config->config_data['invoice_ftp_user'];
		$pass = $this->config->config_data['invoice_ftp_password'];

		$ftp = ftp_connect($host);
		if ($ftp)
		{
			if ($lres = ftp_login($ftp, $user, $pass))
			{
				return $ftp;
			}
		}
	}
}
