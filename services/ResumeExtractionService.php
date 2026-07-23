<?php
// MAMCET Placement & Learning Portal - Resume Text Extraction Service

class ResumeExtractionService {
    /**
     * Central dispatcher to parse and clean text from resumes.
     */
    public static function extractText(string $filePath, string $extension): string {
        $extension = strtolower(trim($extension, '. '));
        $text = '';

        if ($extension === 'docx') {
            $text = self::extractDocx($filePath);
        } elseif ($extension === 'pdf') {
            $text = self::extractPdf($filePath);
        } else {
            throw new Exception("Unsupported file type: '$extension'. Only PDF and DOCX files are allowed.");
        }

        return self::cleanText($text);
    }

    /**
     * Pure PHP parser to open DOCX zip packaging, extract word/document.xml, and format text.
     */
    private static function extractDocx(string $filePath): string {
        if (!class_exists('ZipArchive')) {
            throw new Exception("ZipArchive extension is missing on the server. Please contact system admin.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $xmlFile = 'word/document.xml';
            if (($index = $zip->locateName($xmlFile)) !== false) {
                $xmlContent = $zip->getFromIndex($index);
                $zip->close();

                // Format spacing and convert XML paragraph tags to linebreaks
                $formattedXml = str_replace(
                    ['</w:p>', '<w:tab/>', '</w:r>', '<w:br/>'], 
                    ["\n", " ", "", "\n"], 
                    $xmlContent
                );
                
                $cleanText = strip_tags($formattedXml);
                return html_entity_decode($cleanText, ENT_QUOTES, 'UTF-8');
            }
            $zip->close();
        }
        
        throw new Exception("Unable to extract text from DOCX file. Document may be corrupt.");
    }

    /**
     * Pure PHP parser to scan PDF content streams, decompress FlateDecode blocks, and extract string segments.
     */
    private static function extractPdf(string $filePath): string {
        $content = @file_get_contents($filePath);
        if (empty($content)) {
            throw new Exception("Failed to read PDF file contents.");
        }

        $text = '';
        $offset = 0;

        // Locate and decompress stream objects
        while (($start = strpos($content, 'stream', $offset)) !== false) {
            $start += 6; // Move beyond 'stream'
            
            // Handle carriage returns and line feeds
            if ($content[$start] === "\r") $start++;
            if ($content[$start] === "\n") $start++;
            
            $end = strpos($content, 'endstream', $start);
            if ($end === false) break;
            
            $stream = substr($content, $start, $end - $start);
            $offset = $end + 9;
            
            // Attempt decompression using standard zlib inflate
            $data = @gzuncompress($stream);
            if ($data === false) {
                // If it fails, check if we can inflate omitting the first two bytes
                $data = @gzinflate(substr($stream, 2));
                if ($data === false) {
                    $data = $stream; // Raw fallback
                }
            }
            
            // Read text operators inside text blocks BT ... ET
            if (strpos($data, 'BT') !== false) {
                $btOffset = 0;
                while (($btStart = strpos($data, 'BT', $btOffset)) !== false) {
                    $btStart += 2;
                    $btEnd = strpos($data, 'ET', $btStart);
                    if ($btEnd === false) break;
                    
                    $block = substr($data, $btStart, $btEnd - $btStart);
                    $btOffset = $btEnd + 2;
                    
                    // Match parentheses contents (PDF text layout strings)
                    preg_match_all("/\((.*?)\)/s", $block, $strings);
                    foreach ($strings[1] as $str) {
                        // Unescape PDF bracket configurations
                        $str = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $str);
                        $text .= $str . ' ';
                    }
                    $text .= "\n";
                }
            }
        }

        // Fallback: If no structured text stream blocks were parsed, scan raw parentheses
        if (trim($text) === '') {
            preg_match_all("/\((.*?)\)/s", $content, $strings);
            foreach ($strings[1] as $str) {
                $text .= $str . ' ';
            }
        }

        return $text;
    }

    /**
     * Clean and normalize extracted text layout.
     */
    private static function cleanText(string $text): string {
        // Strip out non-printable ASCII elements
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Replace unicode tabs or repeated white spaces
        $text = preg_replace('/[ \t]+/', ' ', $text);
        
        // Remove excessive empty line breaks
        $text = preg_replace("/[\r\n]+/", "\n", $text);
        
        return trim($text);
    }
}
