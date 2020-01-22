<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Clark Tomlinson <fallen013@gmail.com>
 * @author Frank Karlitschek <frank@karlitschek.de>
 * @author Jakob Sack <mail@jakobsack.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Ko- <k.stoffelen@cs.ru.nl>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Nicolai Ehemann <en@enlightened.de>
 * @author noveens <noveen.sachdeva@research.iiit.ac.in>
 * @author Piotr Filiciak <piotr@filiciak.pl>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Thibaut GRIDEL <tgridel@free.fr>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

use OC\Files\View;
use OC\Streamer;
use OCP\ILogger;
use OCP\Lock\ILockingProvider;

/**
 * Class for file server access
 *
 */
class OC_Files {
	const FILE = 1;
	const ZIP_FILES = 2;
	const ZIP_DIR = 3;

	const UPLOAD_MIN_LIMIT_BYTES = 1048576; // 1 MiB


	private static $multipartBoundary = '';

	/**
	 * @return string
	 */
	private static function getBoundary() {
		if (empty(self::$multipartBoundary)) {
			self::$multipartBoundary = md5(mt_rand());
		}
		return self::$multipartBoundary;
	}

	/**
	 * @param string $filename
	 * @param string $name
	 * @param array $rangeArray ('from'=>int,'to'=>int), ...
	 */
	private static function sendHeaders($filename, $name, array $rangeArray) {
		OC_Response::setContentDispositionHeader($name, 'attachment');
		header('Content-Transfer-Encoding: binary', true);
		header('Pragma: public');// enable caching in IE
		header('Expires: 0');
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		$fileSize = \OC\Files\Filesystem::filesize($filename);
		$type = \OC::$server->getMimeTypeDetector()->getSecureMimeType(\OC\Files\Filesystem::getMimeType($filename));
		if ($fileSize > -1) {
			if (!empty($rangeArray)) {
			    http_response_code(206);
			    header('Accept-Ranges: bytes', true);
			    if (count($rangeArray) > 1) {
				$type = 'multipart/byteranges; boundary='.self::getBoundary();
				// no Content-Length header here
			    }
			    else {
				header(sprintf('Content-Range: bytes %d-%d/%d', $rangeArray[0]['from'], $rangeArray[0]['to'], $fileSize), true);
				OC_Response::setContentLengthHeader($rangeArray[0]['to'] - $rangeArray[0]['from'] + 1);
			    }
			}
			else {
			    OC_Response::setContentLengthHeader($fileSize);
			}
		}
		header('Content-Type: '.$type, true);
	}

	/**
	 * return the content of a file or return a zip file containing multiple files
	 *
	 * @param string $dir
	 * @param string $files ; separated list of files to download
	 * @param array $params ; 'head' boolean to only send header of the request ; 'range' http range header
	 */
	public static function get($dir, $files, $params = null) {

		$view = \OC\Files\Filesystem::getView();
		$getType = self::FILE;
		$filename = $dir;
		try {

			if (is_array($files) && count($files) === 1) {
				$files = $files[0];
			}

			if (!is_array($files)) {
				$filename = $dir . '/' . $files;
				if (!$view->is_dir($filename)) {
					self::getSingleFile($view, $dir, $files, is_null($params) ? array() : $params);
					return;
				}
			}

			$name = 'download';
			if (is_array($files)) {
				$getType = self::ZIP_FILES;
				$basename = basename($dir);
				if ($basename) {
					$name = $basename;
				}

				$filename = $dir . '/' . $name;
			} else {
				$filename = $dir . '/' . $files;
				$getType = self::ZIP_DIR;
				// downloading root ?
				if ($files !== '') {
					$name = $files;
				}
			}

			self::lockFiles($view, $dir, $files);

			/* Calculate filesize and number of files */
			if ($getType === self::ZIP_FILES) {
				$fileInfos = array();
				$fileSize = 0;
				foreach ($files as $file) {
					$fileInfo = \OC\Files\Filesystem::getFileInfo($dir . '/' . $file);
					$fileSize += $fileInfo->getSize();
					$fileInfos[] = $fileInfo;
				}
				$numberOfFiles = self::getNumberOfFiles($fileInfos);
			} elseif ($getType === self::ZIP_DIR) {
				$fileInfo = \OC\Files\Filesystem::getFileInfo($dir . '/' . $files);
				$fileSize = $fileInfo->getSize();
				$numberOfFiles = self::getNumberOfFiles(array($fileInfo));
			}

			$streamer = new Streamer(\OC::$server->getRequest(), $fileSize, $numberOfFiles);
			OC_Util::obEnd();

			$streamer->sendHeaders($name);
			$executionTime = (int)OC::$server->getIniWrapper()->getNumeric('max_execution_time');
			if (strpos(@ini_get('disable_functions'), 'set_time_limit') === false) {
				@set_time_limit(0);
			}
			ignore_user_abort(true);

			if ($getType === self::ZIP_FILES) {
				foreach ($files as $file) {
					$file = $dir . '/' . $file;
					if (\OC\Files\Filesystem::is_file($file)) {
						$userFolder = \OC::$server->getRootFolder()->get(\OC\Files\Filesystem::getRoot());
						$file = $userFolder->get($file);
						if($file instanceof \OC\Files\Node\File) {
							$fh = $file->fopen('r');
							$fileSize = $file->getSize();
							$fileTime = $file->getMTime();
						} else {
							// File is not a file? …
							\OC::$server->getLogger()->debug(
								'File given, but no Node available. Name {file}',
								[ 'app' => 'files', 'file' => $file ]
							);
							continue;
						}
						$streamer->addFileFromStream($fh, $file->getName(), $fileSize, $fileTime);
						fclose($fh);
					} elseif (\OC\Files\Filesystem::is_dir($file)) {
						$streamer->addDirRecursive($file);
					}
				}
			} elseif ($getType === self::ZIP_DIR) {
				$file = $dir . '/' . $files;
				$streamer->addDirRecursive($file);
			}
			$streamer->finalize();
			set_time_limit($executionTime);
			self::unlockAllTheFiles($dir, $files, $getType, $view, $filename);
		} catch (\OCP\Lock\LockedException $ex) {
			self::unlockAllTheFiles($dir, $files, $getType, $view, $filename);
			OC::$server->getLogger()->logException($ex);
			$l = \OC::$server->getL10N('core');
			$hint = method_exists($ex, 'getHint') ? $ex->getHint() : '';
			\OC_Template::printErrorPage($l->t('File is currently busy, please try again later'), $hint, 200);
		} catch (\OCP\Files\ForbiddenException $ex) {
			self::unlockAllTheFiles($dir, $files, $getType, $view, $filename);
			OC::$server->getLogger()->logException($ex);
			$l = \OC::$server->getL10N('core');
			\OC_Template::printErrorPage($l->t('Can\'t read file'), $ex->getMessage(), 200);
		} catch (\Exception $ex) {
			self::unlockAllTheFiles($dir, $files, $getType, $view, $filename);
			OC::$server->getLogger()->logException($ex);
			$l = \OC::$server->getL10N('core');
			$hint = method_exists($ex, 'getHint') ? $ex->getHint() : '';
			\OC_Template::printErrorPage($l->t('Can\'t read file'), $hint, 200);
		}
	}

	/**
	 * @param string $rangeHeaderPos
	 * @param int $fileSize
	 * @return array $rangeArray ('from'=>int,'to'=>int), ...
	 */
	private static function parseHttpRangeHeader($rangeHeaderPos, $fileSize) {
		$rArray=explode(',', $rangeHeaderPos);
		$minOffset = 0;
		$ind = 0;

		$rangeArray = array();

		foreach ($rArray as $value) {
			$ranges = explode('-', $value);
			if (is_numeric($ranges[0])) {
				if ($ranges[0] < $minOffset) { // case: bytes=500-700,601-999
					$ranges[0] = $minOffset;
				}
				if ($ind > 0 && $rangeArray[$ind-1]['to']+1 == $ranges[0]) { // case: bytes=500-600,601-999
					$ind--;
					$ranges[0] = $rangeArray[$ind]['from'];
				}
			}

			if (is_numeric($ranges[0]) && is_numeric($ranges[1]) && $ranges[0] < $fileSize && $ranges[0] <= $ranges[1]) {
				// case: x-x
				if ($ranges[1] >= $fileSize) {
					$ranges[1] = $fileSize-1;
				}
				$rangeArray[$ind++] = array( 'from' => $ranges[0], 'to' => $ranges[1], 'size' => $fileSize );
				$minOffset = $ranges[1] + 1;
				if ($minOffset >= $fileSize) {
					break;
				}
			}
			elseif (is_numeric($ranges[0]) && $ranges[0] < $fileSize) {
				// case: x-
				$rangeArray[$ind++] = array( 'from' => $ranges[0], 'to' => $fileSize-1, 'size' => $fileSize );
				break;
			}
			elseif (is_numeric($ranges[1])) {
				// case: -x
				if ($ranges[1] > $fileSize) {
					$ranges[1] = $fileSize;
				}
				$rangeArray[$ind++] = array( 'from' => $fileSize-$ranges[1], 'to' => $fileSize-1, 'size' => $fileSize );
				break;
			}
		}
		return $rangeArray;
	}

	/**
	 * @param View $view
	 * @param string $name
	 * @param string $dir
	 * @param array $params ; 'head' boolean to only send header of the request ; 'range' http range header
	 */
	private static function getSingleFile($view, $dir, $name, $params) {
		$filename = $dir . '/' . $name;
		OC_Util::obEnd();
		$view->lockFile($filename, ILockingProvider::LOCK_SHARED);
		
		$rangeArray = array();

		if (isset($params['range']) && substr($params['range'], 0, 6) === 'bytes=') {
			$rangeArray = self::parseHttpRangeHeader(substr($params['range'], 6), 
								 \OC\Files\Filesystem::filesize($filename));
		}
		
		if (\OC\Files\Filesystem::isReadable($filename)) {
			self::sendHeaders($filename, $name, $rangeArray);
		} elseif (!\OC\Files\Filesystem::file_exists($filename)) {
			http_response_code(404);
			$tmpl = new OC_Template('', '404', 'guest');
			$tmpl->printPage();
			exit();
		} else {
			http_response_code(403);
			die('403 Forbidden');
		}
		if (isset($params['head']) && $params['head']) {
			return;
		}
		if (!empty($rangeArray)) {
			try {
			    if (count($rangeArray) == 1) {
				$view->readfilePart($filename, $rangeArray[0]['from'], $rangeArray[0]['to']);
			    }
			    else {
				// check if file is seekable (if not throw UnseekableException)
				// we have to check it before body contents
				$view->readfilePart($filename, $rangeArray[0]['size'], $rangeArray[0]['size']);

				$type = \OC::$server->getMimeTypeDetector()->getSecureMimeType(\OC\Files\Filesystem::getMimeType($filename));

				foreach ($rangeArray as $range) {
				    echo "\r\n--".self::getBoundary()."\r\n".
				         "Content-type: ".$type."\r\n".
				         "Content-range: bytes ".$range['from']."-".$range['to']."/".$range['size']."\r\n\r\n";
				    $view->readfilePart($filename, $range['from'], $range['to']);
				}
				echo "\r\n--".self::getBoundary()."--\r\n";
			    }
			} catch (\OCP\Files\UnseekableException $ex) {
			    // file is unseekable
			    header_remove('Accept-Ranges');
			    header_remove('Content-Range');
			    http_response_code(200);
			    self::sendHeaders($filename, $name, array());
			    $view->readfile($filename);
			}
		}
		else {
		    $view->readfile($filename);
		}
	}

	/**
	 * Returns the total (recursive) number of files and folders in the given
	 * FileInfos.
	 *
	 * @param \OCP\Files\FileInfo[] $fileInfos the FileInfos to count
	 * @return int the total number of files and folders
	 */
	private static function getNumberOfFiles($fileInfos) {
		$numberOfFiles = 0;

		$view = new View();

		while ($fileInfo = array_pop($fileInfos)) {
			$numberOfFiles++;

			if ($fileInfo->getType() === \OCP\Files\FileInfo::TYPE_FOLDER) {
				$fileInfos = array_merge($fileInfos, $view->getDirectoryContent($fileInfo->getPath()));
			}
		}

		return $numberOfFiles;
	}

	/**
	 * @param View $view
	 * @param string $dir
	 * @param string[]|string $files
	 */
	public static function lockFiles($view, $dir, $files) {
		if (!is_array($files)) {
			$file = $dir . '/' . $files;
			$files = [$file];
		}
		foreach ($files as $file) {
			$file = $dir . '/' . $file;
			$view->lockFile($file, ILockingProvider::LOCK_SHARED);
			if ($view->is_dir($file)) {
				$contents = $view->getDirectoryContent($file);
				$contents = array_map(function($fileInfo) use ($file) {
					/** @var \OCP\Files\FileInfo $fileInfo */
					return $file . '/' . $fileInfo->getName();
				}, $contents);
				self::lockFiles($view, $dir, $contents);
			}
		}
	}

	/**
	 * @param string $dir
	 * @param $files
	 * @param integer $getType
	 * @param View $view
	 * @param string $filename
	 */
	private static function unlockAllTheFiles($dir, $files, $getType, $view, $filename) {
		if ($getType === self::FILE) {
			$view->unlockFile($filename, ILockingProvider::LOCK_SHARED);
		}
		if ($getType === self::ZIP_FILES) {
			foreach ($files as $file) {
				$file = $dir . '/' . $file;
				$view->unlockFile($file, ILockingProvider::LOCK_SHARED);
			}
		}
		if ($getType === self::ZIP_DIR) {
			$file = $dir . '/' . $files;
			$view->unlockFile($file, ILockingProvider::LOCK_SHARED);
		}
	}

}