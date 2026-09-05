<?php
// MAMCET Placement & Learning Portal - Excel and CSV Processing Helper

require_once(__DIR__ . '/../config/constants.php');

class ExcelHelper {

    /**
     * Read an Excel or CSV file and return rows as an array
     */
    public static function readSheet($filePath, $fileType) {
        $fileType = strtolower($fileType);
        
        if ($fileType === 'csv') {
            return self::readCsv($filePath);
        } elseif ($fileType === 'xml') {
            return self::readXml($filePath);
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
                throw new Exception("Unable to parse Excel file: " . $e->getMessage());
            }
        }
        
        throw new Exception("Unsupported file type: " . $fileType);
    }

    /**
     * Read XML files (supporting SpreadsheetML XML as well as record-based XML schemas)
     */
    private static function readXml($filePath) {
        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            throw new Exception("XML file is empty or could not be read.");
        }

        // Clean XML content
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = !empty($errors) ? $errors[0]->message : 'Invalid XML syntax';
            throw new Exception("Unable to parse XML file: " . trim($msg));
        }

        // Format 1: XML Spreadsheet 2003 (SpreadsheetML)
        $rows = [];
        $tables = $xml->xpath('//ss:Table | //Table');
        if (!empty($tables)) {
            $table = $tables[0];
            $tableRows = $table->xpath('.//ss:Row | .//Row');
            foreach ($tableRows as $tr) {
                $rowCells = [];
                $cells = $tr->xpath('.//ss:Cell | .//Cell');
                foreach ($cells as $c) {
                    $dataNodes = $c->xpath('.//ss:Data | .//Data');
                    $val = !empty($dataNodes) ? (string)$dataNodes[0] : (string)$c;
                    $rowCells[] = trim($val);
                }
                if (!empty(array_filter($rowCells, fn($v) => $v !== ''))) {
                    $rows[] = $rowCells;
                }
            }
            if (!empty($rows)) {
                return $rows;
            }
        }

        // Format 2: Record-based XML (e.g. <students><student><reg_no>...</reg_no>...</student></students>)
        $children = $xml->children();
        if (count($children) === 1 && count($children[0]->children()) > 0) {
            $records = $children[0]->children();
        } else {
            $records = $children;
        }

        if (count($records) > 0) {
            $headers = [];
            foreach ($records as $rec) {
                foreach ($rec->children() as $field => $val) {
                    if (!in_array((string)$field, $headers, true)) {
                        $headers[] = (string)$field;
                    }
                }
            }

            if (!empty($headers)) {
                $rows[] = $headers;
                foreach ($records as $rec) {
                    $row = [];
                    foreach ($headers as $h) {
                        $val = isset($rec->$h) ? (string)$rec->$h : '';
                        $row[] = trim($val);
                    }
                    if (!empty(array_filter($row, fn($v) => $v !== ''))) {
                        $rows[] = $row;
                    }
                }
                return $rows;
            }
        }

        throw new Exception("No recognizable tabular data or student records found in the XML document.");
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
                $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
                if (strpos($firstLine, ";") !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
                    $delimiter = ";";
                } elseif (strpos($firstLine, "\t") !== false && substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
                    $delimiter = "\t";
                }
                rewind($handle);
            }

            $isFirst = true;
            while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
                if ($isFirst && !empty($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$data[0]);
                    $isFirst = false;
                }
                // Sanitize and trim
                $rows[] = array_map(function($val) {
                    $val = preg_replace('/^\xEF\xBB\xBF/', '', (string)$val);
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
            throw new Exception("Failed to open Excel archive (.xlsx).");
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
            // Try to find first sheet from workbook.xml
            for ($i = 1; $i <= 5; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXml) break;
            }
        }

        if (!$sheetXml) {
            $zip->close();
            throw new Exception("Sheet structure not found inside Excel file.");
        }
        
        $xml = new SimpleXMLElement($sheetXml);
        $rows = [];
        
        if (isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $rowNode) {
                $rowIndex = (int)$rowNode['r'];
                $rowData = [];
                
                foreach ($rowNode->c as $cellNode) {
                    $cellRef = (string)$cellNode['r'];
                    preg_match('/^[A-Z]+/', $cellRef, $matches);
                    $colLetter = $matches[0] ?? 'A';
                    
                    $val = '';
                    $type = (string)($cellNode['t'] ?? '');

                    if ($type === 's') {
                        $idx = (int)$cellNode->v;
                        $val = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr' && isset($cellNode->is->t)) {
                        $val = (string)$cellNode->is->t;
                    } elseif (isset($cellNode->v)) {
                        $val = (string)$cellNode->v;
                    }
                    
                    $rowData[$colLetter] = trim($val);
                }
                $rows[$rowIndex] = $rowData;
            }
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
     * Create a native Microsoft Excel (.xlsx) file without Composer dependencies
     *
     * @param array $rows 2D array of rows
     * @param string|null $outputPath Output file path or null to return file bytes
     * @return string File bytes or saved output path
     */
    public static function createXlsx(array $rows, ?string $outputPath = null, string $sheetName = 'Sheet1'): string {
        $tempFile = tempnam(sys_get_temp_dir(), 'gen_xlsx_') . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Failed to initialize Excel zip archive.");
        }

        // 1. [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">\n" .
            "  <Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>\n" .
            "  <Default Extension=\"xml\" ContentType=\"application/xml\"/>\n" .
            "  <Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>\n" .
            "  <Override PartName=\"/xl/worksheets/sheet1.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n" .
            "  <Override PartName=\"/xl/sharedStrings.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml\"/>\n" .
            "  <Override PartName=\"/xl/styles.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml\"/>\n" .
            "</Types>");

        // 2. _rels/.rels
        $zip->addFromString('_rels/.rels', "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">\n" .
            "  <Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Target=\"xl/workbook.xml\"/>\n" .
            "</Relationships>");

        // 3. xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">\n" .
            "  <Relationship Id=\"rId1\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet1.xml\"/>\n" .
            "  <Relationship Id=\"rId2\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\" Target=\"sharedStrings.xml\"/>\n" .
            "  <Relationship Id=\"rId3\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>\n" .
            "</Relationships>");

        // 4. xl/workbook.xml
        $safeSheetName = htmlspecialchars($sheetName ?: 'Sheet1', ENT_XML1, 'UTF-8');
        $zip->addFromString('xl/workbook.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">\n" .
            "  <sheets>\n" .
            "    <sheet name=\"{$safeSheetName}\" sheetId=\"1\" r:id=\"rId1\"/>\n" .
            "  </sheets>\n" .
            "</workbook>");

        // 5. xl/styles.xml
        $zip->addFromString('xl/styles.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<styleSheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n" .
            "  <fonts count=\"2\">\n" .
            "    <font><sz val=\"11\"/><name val=\"Calibri\"/></font>\n" .
            "    <font><b/><sz val=\"11\"/><color rgb=\"FF0B3D91\"/><name val=\"Calibri\"/></font>\n" .
            "  </fonts>\n" .
            "  <fills count=\"2\"><fill><patternFill patternType=\"none\"/></fill><fill><patternFill patternType=\"gray125\"/></fill></fills>\n" .
            "  <borders count=\"1\"><border><left/><right/><top/><bottom/><diagonal/></border></borders>\n" .
            "  <cellStyleXfs count=\"1\"><xf numFmtId=\"0\" fontId=\"0\" fillId=\"0\" borderId=\"0\"/></cellStyleXfs>\n" .
            "  <cellXfs count=\"2\">\n" .
            "    <xf numFmtId=\"0\" fontId=\"0\" fillId=\"0\" borderId=\"0\" xfId=\"0\"/>\n" .
            "    <xf numFmtId=\"0\" fontId=\"1\" fillId=\"0\" borderId=\"0\" xfId=\"0\" applyFont=\"1\"/>\n" .
            "  </cellXfs>\n" .
            "</styleSheet>");

        // 6. Build Shared Strings & Sheet Data
        $stringMap = [];
        $uniqueStrings = [];
        $sheetXml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n" .
            "  <sheetData>\n";

        foreach ($rows as $rowIndex => $row) {
            $rNum = $rowIndex + 1;
            $isHeader = ($rowIndex === 0);
            $styleAttr = $isHeader ? " s=\"1\"" : "";

            $sheetXml .= "    <row r=\"{$rNum}\">\n";
            $colIndex = 0;
            foreach ($row as $val) {
                $colLetter = self::indexToColLetter($colIndex);
                $cellRef = "{$colLetter}{$rNum}";
                $strVal = (string)$val;

                if ($strVal === '') {
                    $colIndex++;
                    continue;
                }

                if (!isset($stringMap[$strVal])) {
                    $stringMap[$strVal] = count($uniqueStrings);
                    $uniqueStrings[] = $strVal;
                }
                $sId = $stringMap[$strVal];

                $sheetXml .= "      <c r=\"{$cellRef}\" t=\"s\"{$styleAttr}><v>{$sId}</v></c>\n";
                $colIndex++;
            }
            $sheetXml .= "    </row>\n";
        }
        $sheetXml .= "  </sheetData>\n</worksheet>";
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        // 7. xl/sharedStrings.xml
        $sstXml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n" .
            "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"" . count($uniqueStrings) . "\" uniqueCount=\"" . count($uniqueStrings) . "\">\n";
        foreach ($uniqueStrings as $str) {
            $sstXml .= "  <si><t xml:space=\"preserve\">" . htmlspecialchars($str, ENT_XML1, 'UTF-8') . "</t></si>\n";
        }
        $sstXml .= "</sst>";
        $zip->addFromString('xl/sharedStrings.xml', $sstXml);

        $zip->close();

        if ($outputPath !== null) {
            copy($tempFile, $outputPath);
            @unlink($tempFile);
            return $outputPath;
        }

        $content = file_get_contents($tempFile);
        @unlink($tempFile);
        return $content;
    }

    /**
     * Convert 0-based column index to Excel column letter (0->A, 25->Z, 26->AA)
     */
    public static function indexToColLetter(int $index): string {
        $letter = '';
        while ($index >= 0) {
            $letter = chr($index % 26 + 65) . $letter;
            $index = intval($index / 26) - 1;
        }
        return $letter;
    }

    /**
     * Convert Excel column letters (A, B, C... Z, AA) to 0-based indices
     */
    public static function colLetterToIndex($col) {
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
            // Strip BOM, non-printable characters, whitespace, quotes
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
            $clean = strtolower(trim($clean, " \t\n\r\0\x0B\"'"));
            if (empty($clean)) continue;

            $cleanNoUnder = str_replace(['_', '-'], ' ', $clean);
            $cleanCompact = preg_replace('/[^a-z0-9]/', '', $clean);
            
            // 1. Try direct exact match (with raw key, clean, cleanNoUnder, cleanCompact)
            $found = false;
            foreach ($mappings as $dbField => $aliases) {
                $dbFieldClean = strtolower($dbField);
                $dbFieldNoUnder = str_replace(['_', '-'], ' ', $dbFieldClean);
                $dbFieldCompact = preg_replace('/[^a-z0-9]/', '', $dbFieldClean);

                if ($clean === $dbFieldClean || 
                    $cleanNoUnder === $dbFieldNoUnder || 
                    $cleanCompact === $dbFieldCompact) {
                    $mapped[$dbField] = $index;
                    $found = true;
                    break;
                }

                foreach ($aliases as $alias) {
                    $aliasClean = strtolower(trim($alias));
                    $aliasNoUnder = str_replace(['_', '-'], ' ', $aliasClean);
                    $aliasCompact = preg_replace('/[^a-z0-9]/', '', $aliasClean);

                    if ($clean === $aliasClean || 
                        $cleanNoUnder === $aliasNoUnder || 
                        $cleanCompact === $aliasCompact) {
                        $mapped[$dbField] = $index;
                        $found = true;
                        break 2;
                    }
                }
            }
            
            // 2. Fuzzy containment matches if not matched yet
            if (!$found) {
                foreach ($mappings as $dbField => $aliases) {
                    if (isset($mapped[$dbField])) continue;
                    
                    $dbFieldClean = strtolower($dbField);
                    if (strlen($clean) >= 3 && (strpos($dbFieldClean, $clean) !== false || strpos($clean, $dbFieldClean) !== false)) {
                        $mapped[$dbField] = $index;
                        break;
                    }

                    foreach ($aliases as $alias) {
                        $aliasClean = strtolower(trim($alias));
                        $aliasNoUnder = str_replace(['_', '-'], ' ', $aliasClean);
                        
                        if (strlen($clean) >= 3 && strlen($aliasClean) >= 3 && 
                            (strpos($clean, $aliasClean) !== false || 
                             strpos($cleanNoUnder, $aliasNoUnder) !== false || 
                             strpos($aliasClean, $clean) !== false ||
                             strpos($aliasNoUnder, $cleanNoUnder) !== false)) {
                            $mapped[$dbField] = $index;
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $mapped;
    }
}
