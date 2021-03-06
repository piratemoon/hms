<?php

	//! Class to handle reading CSV files. Warning, reads entire file in to memory.
	class CsvReader
	{

		var $lines = array();	//!< Array of lines found in the file

		//! Attempt to read a .csv file
		/*!
			@param string $filePath The path to look for the file.
			@retval bool True if file was opened successfully, false otherwise.
		*/
		public function readFile($filePath)
		{
			if(!is_string($filePath))
			{
				return false;
			}

			$fileHandle = fopen($filePath, 'r');

			if($fileHandle == 0)
			{
				return false;
			}

			$this->lines = array();

			while (($data = fgetcsv($fileHandle)) !== FALSE) 
			{
				// Ignore blank lines
				if($data != null)
				{
					array_push($this->lines, $data);
				}
			}

			if(count($this->lines) <= 0)
			{
				return false;
			}

			fclose($fileHandle);

			return true;
		}

		//! Get the number of lines available.
		/*!
			@retval int The number of lines available.
		*/
		public function getNumLines()
		{
			return count($this->lines);
		}

		//! Get the line at index.
		/*!
			@param int $index The index of the line to retrieve.
			@retval mixed An array of line data if index is valid, otherwise null.
		*/
		public function getLine($index)
		{
			if(	$index >= 0 &&
				$index < $this->getNumLines())
			{
				return $this->lines[$index];
			}

			return null;
		}
	}
?>