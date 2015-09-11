<?php namespace Indemnity83\SolderToolbelt;

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;

class ModCommand extends Command {

	/**
	 * Configure the command options.
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this->setName('mod')
			->setDescription('Work with mods on the TechnicSolder site')
			->addArgument(
					'action',
					InputArgument::REQUIRED,
					'info, get, pack, put'
				)
			->addArgument(
				 'mod',
				 InputArgument::REQUIRED,
				 'The filename or slug of the mod you wish to request'
			)
			->addArgument(
				 'version',
				 InputArgument::OPTIONAL,
				 'Specific modversion you wish to view'
			);
	}

	/**
	 * Execute the command.
	 *
	 * @param	\Symfony\Component\Console\Input\InputInterface	$input
	 * @param	\Symfony\Component\Console\Output\OutputInterface	$output
	 * @return void
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		$commandAction = $input->getArgument('action');
		$modName = $input->getArgument('mod');
		$modVersion = $input->getArgument('version');

		switch( $commandAction ) {
			case 'info':
				if( file_exists(realpath($modName)) ) {
					$this->infoModFile($output, realpath($modName));
				} else {
					$this->infoModApi($output, $modName, $modVersion);
				}
				break;
			case 'get':
				$this->getMod($output, $modName, $modVersion);
				break;
			case 'pack':
				$this->packModFile($input, $output, realpath($modName));
				break;
			case 'put':
				$this->putMod($input, $output, realpath($modName));
				break;
			default:
				throw new \InvalidArgumentException('Invalid arguments');
		}
	}

	private function infoModFile($output, $modFile)
	{
		$zip = new \ZipArchive;
		if ($zip->open($modFile) === TRUE) {
			$modList = json_decode($zip->getFromName('mcmod.info'));
			$zip->close();
		} else {
			throw new \OutOfBoundsException('Could not identify mod');
		}

		if( isset($modList->modlist)) {
			$modList = $modList->modlist;
		}

		foreach( $modList as $mod ) {
			$output->writeln('');
			$rows = array();
			foreach( $mod as $key => $value ) {
				if( is_array($value) ) {
					$rows[] = array("<info>$key</info>", implode($value,"\n"));
				} else {
					$rows[] = array("<info>$key</info>", mb_strimwidth($value, 0, 60, "..."));
				}
			}
			$output->writeln("<comment>{$mod->name}:</comment>");
			$table = new Table($output);
			$table
					->setRows($rows)
					->setStyle('compact')
					->render();
		}

		$output->writeln('');
	}

	private function infoModApi($output, $slug, $version)
	{
		displayServerInfo($output);

		$apiClient = new Client();
		$appConfig = solder_config();

		$apiResponse = $apiClient->get($appConfig->api . '/mod/' . $slug . '/' . $version)->json();
		if(isset($apiResponse['error'])) {
			throw new \Exception($apiResponse['error']);
		}

		$rows = array();
		foreach( $apiResponse as $key => $value ) {
			if( $key == 'versions' ) {
				$rows[] = array("<info>$key</info>", implode($value,"\n"));
			} else {
				$rows[] = array("<info>$key</info>", mb_strimwidth($value, 0, 60, "..."));
			}
		}

		$output->writeln('');
		$output->writeln("<comment>Mod:</comment>");
		$table = new Table($output);
		$table
				->setRows($rows)
				->setStyle('compact')
				->render();
	}

	private function getMod($output, $slug, $version)
	{
		if($slug == '' || $version == '') {
			throw new \InvalidArgumentException('Invalid arguments');
		}

		$apiClient = new Client();
		$appConfig = solder_config();

		$apiResponse = $apiClient->get($appConfig->api . '/mod/' . $slug . '/' . $version)->json();
		if(isset($apiResponse['error'])) {
			throw new \Exception($apiResponse['error']);
		}

		$url = $apiResponse['url'];
		$filename = basename($url);
		$md5 = $apiResponse['md5'];

		downloadFile($url, $filename, $output, $md5);
		$output->writeln('');

		if( md5_file($filename) != $md5 ) {
			throw new \Exception('Hash dosen\'t match');
		}
	}

	private function packModFile($input, $output, $modFile)
	{
		$helper = $this->getHelper('question');
		$zip = new \ZipArchive;

		if ($zip->open($modFile) === TRUE) {
			$modList = json_decode($zip->getFromName('mcmod.info'));
			$zip->close();
		} else {
			throw new \OutOfBoundsException('Could not identify mod');
		}

		if( isset($modList->modlist)) {
			$modList = $modList->modlist;
		}

		if( count($modList) > 1 ) {
			$options = array();
			foreach( $modList as $mod ) {
				$options[] = $mod->name;
			}

			$question = new ChoiceQuestion(
				"Mod List contains multiple definitions, please select the defintion to be used (default is `{$options[0]}`) ",
				$options,
				'0'
			);
			$question->setErrorMessage('%s is invalid.');
			$response = $helper->ask($input, $output, $question);
			$modsRow = array_search($response, $options);
			$output->writeln('');
		} else {
			$modsRow = 0;
		}

		if( isset($modList[$modsRow]->name) ) {
			$modName = $modList[$modsRow]->name;
		} else {
			$question = new Question('Please enter the name of the mod: ');
			$modName = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->version) ) {
			$modVersion = $modList[$modsRow]->version;
		} else {
			$question = new Question('Please enter the version of the mod: ');
			$modVersion = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->mcversion) ) {
			$mcVersion = $modList[$modsRow]->mcversion;
		} else {
			$question = new Question('Please enter the version of minecraft this is for: ');
			$mcVersion = $helper->ask($input, $output, $question);
		}

		$modSlug = slug($modName);
		$packName = $modSlug . '-' . $mcVersion  . '-' . $modVersion;
		$fileName = $modSlug . DIRECTORY_SEPARATOR . $packName . '.zip';
		$output->writeln("Archive: $packName.zip");

		if(!is_dir($modSlug)){
			$output->writeln("   creating: $modSlug" . DIRECTORY_SEPARATOR);
			mkdir($modSlug);
		}

		if ($zip->open($fileName, \ZipArchive::OVERWRITE) === TRUE) {
			$output->writeln("   deflating: " . basename($modFile));
			$zip->addFile($modFile, 'mods' . DIRECTORY_SEPARATOR . basename($modFile));
			$zip->close();
		} else {
			throw new \OutOfBoundsException('Could not write to file');
		}
	}

	private function putMod($input, $output, $modFile)
	{
		$helper = $this->getHelper('question');
		$zip = new \ZipArchive;

		if ($zip->open($modFile) === TRUE) {
			$modList = json_decode($zip->getFromName('mcmod.info'));
			$zip->close();
		} else {
			throw new \OutOfBoundsException('Could not identify mod');
		}

		if( isset($modList->modlist)) {
			$modList = $modList->modlist;
		}

		if( count($modList) > 1 ) {
			$options = array();
			foreach( $modList as $mod ) {
				$options[] = $mod->name;
			}

			$question = new ChoiceQuestion(
				"Mod List contains multiple definitions, please select the defintion to be used (default is `{$options[0]}`) ",
				$options,
				'0'
			);
			$question->setErrorMessage('%s is invalid.');
			$response = $helper->ask($input, $output, $question);
			$modsRow = array_search($response, $options);
			$output->writeln('');
		} else {
			$modsRow = 0;
		}

		if( isset($modList[$modsRow]->name) ) {
			$modName = $modList[$modsRow]->name;
		} else {
			$question = new Question('Mod Name: ');
			$modName = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->version) ) {
			$modVersion = $modList[$modsRow]->version;
		} else {
			$question = new Question('Mod Version: ');
			$modVersion = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->mcversion) ) {
			$mcVersion = $modList[$modsRow]->mcversion;
		} else {
			$question = new Question('Minecraft Version: ');
			$mcVersion = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->authors) ) {
			$modAuthors = implode($modList[$modsRow]->authors, ', ');
		} else {
			$question = new Question('Mod Author(s): ');
			$modAuthors = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->description) ) {
			$modDescription = $modList[$modsRow]->description;
		} else {
			$question = new Question('Mod Description: ');
			$modDescription = $helper->ask($input, $output, $question);
		}

		if( isset($modList[$modsRow]->url) ) {
			$modWebsite = $modList[$modsRow]->url;
		} else {
			$question = new Question('Mod Website: ');
			$modWebsite = $helper->ask($input, $output, $question);
		}

		$modSlug = slug($modName);
		$packName = $modSlug . '-' . $mcVersion  . '-' . $modVersion;
		$fileName = $modSlug . DIRECTORY_SEPARATOR . $packName . '.zip';

		$apiClient = new Client();
		$appConfig = solder_config();
		$appHost = parse_url($appConfig->api)['host'];
		$apiResponse = $apiClient->get($appConfig->api . '/mod/' . $modSlug . '/' . $mcVersion . '-' . $modVersion)->json();
		if (!isset($apiResponse['error'])) {
			throw new \Exception('Mod version already exists');
		} elseif (isset($apiResponse['error']) && $apiResponse['error'] == 'Mod does not exist') {
			$output->writeln("   adding: $modName to $appHost");
		} elseif (isset($apiResponse['error']) && $apiResponse['error'] == 'Mod version does not exist') {
			// no op, we want this situation
		} elseif (isset($apiResponse['error'])) {
			throw new \Exception($apiResponse['error']);
		}

		if(!is_dir($modSlug)){
			$output->writeln("   creating: $modSlug" . DIRECTORY_SEPARATOR);
			mkdir($modSlug);
		}

		if ($zip->open($fileName, \ZipArchive::OVERWRITE) === TRUE) {
			$output->writeln("   deflating: " . basename($modFile));
			$zip->addFile($modFile, 'mods' . DIRECTORY_SEPARATOR . basename($modFile));
			$zip->close();
		} else {
			throw new \OutOfBoundsException('Could not write to file');
		}

		$output->writeln("   uploading: $modName $mcVersion-$modVersion to $appHost");

	}

}
