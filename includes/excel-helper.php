<?php
// MAMCET Placement & Learning Portal - Excel and CSV Processing Helper

class ExcelHelper {

    /**
     * Read an Excel or CSV file and return rows as an array
     */
    public static function readSheet($filePath, $fileType) {
        $fileType = strtolower($fileType);
        
        if ($fileType === 'csv') {
            return self::readCsv($filePath);
        } elseif ($fileType === 'xlsx' || $fileType === 'xls') {
            // First try using custom XML parser which doesn't require Composer dependencies
            try {
                return self::readXlsxLightweight($filePath);
            } catch (Exception $e) {
                // Fallback to PhpSpreadsheet if autoload is available
                $autoloadPath = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoloadPath)) {
                    require_once($autoloadPath);
                    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                        return self::readWithPhpSpreadsheet($filePath);
                    }
                }
                throw new Exception("Unable to parse Excel file. Lightweight parser failed and PhpSpreadsheet is not installed: " . $e->getMessage());
            }
        }
        
        throw new Exception("Unsupported file type: " . $fileType);
    }

    /**
     * Read CSV files using native fgetcsv
     */
    private static function readCsv($filePath) {
        $rows = [];
        if (($handle = fopen($filePath, "r")) !== FALSE) {
            // Detect delimiter
            $delimiter = ",";
            $firstLine = fgets($handle);
            if ($firstLine !== false) {
                if (strpos($firstLine, ";") !== false) {
                    $delimiter = ";";
                } elseif (strpos($firstLine, "\t") !== false) {
                    $delimiter = "\t";
                }
                rewind($handle);
            }

            while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                // Sanitize and trim
                $rows[] = array_map(function($val) {
                    return trim(mb_convert_encoding($val ?? '', 'UTF-8', 'UTF-8'));
                }, $data);
            }
            fclose($handle);
        }
        return $rows;
    }

    /**
     * Lightweight custom XML parser for XLSX files (no Composer required)
     */
    private static function readXlsxLightweight($filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            throw new Exception("Failed to open Excel zip archive.");
        }
        
        // 1. Read shared strings
        $sharedStrings = [];
        $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($stringsXml) {
            $xml = new SimpleXMLElement($stringsXml);
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $sharedStrings[] = $text;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
        
        // 2. Read sheet1
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $zip->close();
            throw new Exception("Sheet structure not found inside Excel file.");
        }
        
        $xml = new SimpleXMLElement($sheetXml);
        $rows = [];
        
        foreach ($xml->sheetData->row as $rowNode) {
            $rowIndex = (int)$rowNode['r'];
            $rowData = [];
            
            foreach ($rowNode->c as $cellNode) {
                $cellRef = (string)$cellNode['r'];
                preg_match('/^[A-Z]+/', $cellRef, $matches);
                $colLetter = $matches[0];
                
                $val = '';
                if (isset($cellNode->v)) {
                    $val = (string)$cellNode->v;
                    $type = (string)$cellNode['t'];
                    
                    if ($type === 's') {
                        $idx = (int)$val;
                        $val = $sharedStrings[$idx] ?? '';
                    }
                }
                
                // If it is a date formatted cell, parse or normalize it
                $rowData[$colLetter] = trim($val);
            }
            $rows[$rowIndex] = $rowData;
        }
        
        $zip->close();
        
        if (empty($rows)) {
            return [];
        }
        
        // Convert letter keys to numeric indices
        $finalRows = [];
        $maxColIdx = 0;
        
        // Pre-scan to find the maximum column index
        foreach ($rows as $rData) {
            foreach ($rData as $colL => $val) {
                $idx = self::colLetterToIndex($colL);
                if ($idx > $maxColIdx) {
                    $maxColIdx = $idx;
                }
            }
        }
        
        // Re-construct the grid
        ksort($rows);
        foreach ($rows as $rIndex => $rData) {
            $numericRow = array_fill(0, $maxColIdx + 1, '');
            foreach ($rData as $colL => $val) {
                $idx = self::colLetterToIndex($colL);
                $numericRow[$idx] = $val;
            }
            $finalRows[] = $numericRow;
        }
        
        return $finalRows;
    }

    /**
     * Convert Excel column letters (A, B, C... Z, AA) to 0-based indices
     */
    private static function colLetterToIndex($col) {
        $col = strtoupper($col);
        $len = strlen($col);
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * Read XLSX using PhpSpreadsheet (fallback)
     */
    private static function readWithPhpSpreadsheet($filePath) {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(FALSE);
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = (string)$cell->getValue();
            }
            $rows[] = $rowData;
        }
        return $rows;
    }

    /**
     * Helper to perform fuzzy column matching based on pre-defined equivalents
     */
    public static function autoMapColumns($headers) {
        $mappings = EXCEL_COLUMN_MAPPINGS;
        $mapped = [];
        
        foreach ($headers as $index => $header) {
            $cleanHeader = strtolower(trim($header));
            if (empty($cleanHeader)) continue;
            
            // Try direct matches first
            foreach ($mappings as $dbField => $aliases) {
                if ($cleanHeader === strtolower($dbField) || in_array($cleanHeader, $aliases)) {
                    $mapped[$dbField] = $index;
                    break;
                }
            }
            
            // Fuzzy containment matches if not matched yet
            foreach ($mappings as $dbField => $aliases) {
                if (isset($mapped[$dbField])) continue;
                foreach ($aliases as $alias) {
                    if (strpos($cleanHeader, strtolower($alias)) !== false || strpos(strtolower($alias), $cleanHeader) !== false) {
                        $mapped[$dbField] = $index;
                        break 2;
                    }
                }
            }
        }
        
        return $mapped;
    }
}
