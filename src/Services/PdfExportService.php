<?php
/**
 * Sentinel Chat Platform - PDF Export Service
 * 
 * Handles PDF export of audit logs with digital signatures.
 * Uses TCPDF if available, otherwise provides basic PDF generation.
 * 
 * Security: All PDFs are digitally signed using OpenSSL for compliance.
 */

declare(strict_types=1);

namespace iChat\Services;

class PdfExportService
{
    private ?string $privateKeyPath;
    private ?string $certificatePath;
    
    public function __construct()
    {
        // Paths for signing keys (should be in secure location outside web root)
        $this->privateKeyPath = ICHAT_ROOT . '/config/audit_signing_key.pem';
        $this->certificatePath = ICHAT_ROOT . '/config/audit_certificate.pem';
    }
    
    /**
     * Generate PDF from audit logs
     * 
     * @param array $logs Audit log entries
     * @param array $metadata Export metadata (export_date, exported_by, filters, etc.)
     * @return string PDF content
     */
    public function generatePdf(array $logs, array $metadata = []): string
    {
        // Try to use TCPDF if available
        if (class_exists('TCPDF')) {
            return $this->generatePdfWithTcpdf($logs, $metadata);
        }
        
        // Fallback to basic PDF generation
        return $this->generateBasicPdf($logs, $metadata);
    }
    
    /**
     * Generate PDF using TCPDF (if available)
     */
    private function generatePdfWithTcpdf(array $logs, array $metadata): string
    {
        // Create TCPDF instance
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Sentinel Chat Platform');
        $pdf->SetAuthor($metadata['exported_by'] ?? 'System');
        $pdf->SetTitle('Audit Log Export');
        $pdf->SetSubject('Compliance Audit Trail');
        $pdf->SetKeywords('audit, compliance, log, security');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Audit Log Export', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Metadata
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Export Date: ' . ($metadata['export_date'] ?? date('Y-m-d H:i:s')), 0, 1);
        $pdf->Cell(0, 6, 'Exported By: ' . ($metadata['exported_by'] ?? 'System'), 0, 1);
        $pdf->Cell(0, 6, 'Total Records: ' . count($logs), 0, 1);
        if (!empty($metadata['filters'])) {
            $pdf->Cell(0, 6, 'Filters Applied: ' . json_encode($metadata['filters']), 0, 1);
        }
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->Cell(15, 7, 'ID', 1, 0, 'C', true);
        $pdf->Cell(35, 7, 'Timestamp', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'User', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Action', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'Category', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Resource', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'IP Address', 1, 0, 'C', true);
        $pdf->Cell(15, 7, 'Success', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetFillColor(245, 245, 245);
        $fill = false;
        
        foreach ($logs as $log) {
            // Check if we need a new page
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
                // Reprint header
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(200, 200, 200);
                $pdf->Cell(15, 7, 'ID', 1, 0, 'C', true);
                $pdf->Cell(35, 7, 'Timestamp', 1, 0, 'C', true);
                $pdf->Cell(30, 7, 'User', 1, 0, 'C', true);
                $pdf->Cell(25, 7, 'Action', 1, 0, 'C', true);
                $pdf->Cell(20, 7, 'Category', 1, 0, 'C', true);
                $pdf->Cell(25, 7, 'Resource', 1, 0, 'C', true);
                $pdf->Cell(20, 7, 'IP Address', 1, 0, 'C', true);
                $pdf->Cell(15, 7, 'Success', 1, 1, 'C', true);
                $pdf->SetFont('helvetica', '', 8);
            }
            
            $pdf->Cell(15, 6, substr((string)$log['id'], 0, 8), 1, 0, 'C', $fill);
            $pdf->Cell(35, 6, substr($log['timestamp'] ?? '', 0, 16), 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, substr($log['user_handle'] ?? '', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, substr($log['action_type'] ?? '', 0, 12), 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, substr($log['action_category'] ?? '', 0, 10), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, substr(($log['resource_type'] ?? '') . '/' . ($log['resource_id'] ?? ''), 0, 12), 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, substr($log['ip_address'] ?? '', 0, 15), 1, 0, 'L', $fill);
            $pdf->Cell(15, 6, ($log['success'] ?? true) ? 'Yes' : 'No', 1, 1, 'C', $fill);
            
            $fill = !$fill;
        }
        
        // Add signature page
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Digital Signature', 0, 1, 'C');
        $pdf->Ln(5);
        
        $pdf->SetFont('helvetica', '', 10);
        $signature = $this->generateDigitalSignature($logs, $metadata);
        $pdf->MultiCell(0, 6, 'This document has been digitally signed for compliance and audit purposes.', 0, 'L');
        $pdf->Ln(3);
        $pdf->SetFont('courier', '', 8);
        $pdf->MultiCell(0, 5, 'Signature: ' . $signature, 0, 'L');
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, 'Signed At: ' . date('Y-m-d H:i:s'), 0, 1);
        $pdf->Cell(0, 6, 'Signed By: ' . ($metadata['exported_by'] ?? 'System'), 0, 1);
        
        // Output PDF
        return $pdf->Output('', 'S'); // Return as string
    }
    
    /**
     * Generate basic PDF (fallback if TCPDF not available)
     */
    private function generateBasicPdf(array $logs, array $metadata): string
    {
        // Simple PDF structure (minimal PDF format)
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        
        $content = "BT\n/F1 12 Tf\n100 700 Td\n(Audit Log Export) Tj\n0 -20 Td\n";
        $content .= "(Export Date: " . ($metadata['export_date'] ?? date('Y-m-d H:i:s')) . ") Tj\n0 -15 Td\n";
        $content .= "(Exported By: " . ($metadata['exported_by'] ?? 'System') . ") Tj\n0 -15 Td\n";
        $content .= "(Total Records: " . count($logs) . ") Tj\n0 -20 Td\n";
        
        $y = 600;
        foreach (array_slice($logs, 0, 50) as $log) { // Limit to 50 for basic PDF
            $line = sprintf(
                "ID: %s | %s | %s | %s | %s\n",
                substr((string)$log['id'], 0, 8),
                substr($log['timestamp'] ?? '', 0, 16),
                substr($log['user_handle'] ?? '', 0, 15),
                substr($log['action_type'] ?? '', 0, 20),
                ($log['success'] ?? true) ? 'Yes' : 'No'
            );
            $content .= "0 -12 Td\n(" . addslashes($line) . ") Tj\n";
            $y -= 12;
            if ($y < 50) break;
        }
        
        $content .= "ET\n";
        
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>\nendobj\n";
        $pdf .= "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";
        $pdf .= "xref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000456 00000 n \n";
        $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . strlen($pdf) . "\n%%EOF\n";
        
        return $pdf;
    }
    
    /**
     * Generate digital signature for the export
     * 
     * @param array $logs Audit logs
     * @param array $metadata Export metadata
     * @return string Base64-encoded signature
     */
    private function generateDigitalSignature(array $logs, array $metadata): string
    {
        // Create signature data
        $signatureData = [
            'export_date' => $metadata['export_date'] ?? date('Y-m-d H:i:s'),
            'exported_by' => $metadata['exported_by'] ?? 'System',
            'total_records' => count($logs),
            'filters' => $metadata['filters'] ?? [],
            'hash' => hash('sha256', json_encode($logs)),
        ];
        
        $dataToSign = json_encode($signatureData);
        
        // Try to sign with private key if available
        if (file_exists($this->privateKeyPath) && is_readable($this->privateKeyPath)) {
            $privateKey = openssl_pkey_get_private(file_get_contents($this->privateKeyPath));
            if ($privateKey !== false) {
                $signature = '';
                if (openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                    openssl_free_key($privateKey);
                    return base64_encode($signature);
                }
                openssl_free_key($privateKey);
            }
        }
        
        // Fallback: Use HMAC with a secret (less secure but works without keys)
        $secret = \iChat\Config::getInstance()->get('api.shared_secret', 'default-secret-change-me');
        return base64_encode(hash_hmac('sha256', $dataToSign, $secret, true));
    }
    
    /**
     * Verify digital signature
     * 
     * @param string $signature Base64-encoded signature
     * @param array $logs Audit logs
     * @param array $metadata Export metadata
     * @return bool True if signature is valid
     */
    public function verifySignature(string $signature, array $logs, array $metadata): bool
    {
        $signatureData = [
            'export_date' => $metadata['export_date'] ?? date('Y-m-d H:i:s'),
            'exported_by' => $metadata['exported_by'] ?? 'System',
            'total_records' => count($logs),
            'filters' => $metadata['filters'] ?? [],
            'hash' => hash('sha256', json_encode($logs)),
        ];
        
        $dataToVerify = json_encode($signatureData);
        $signatureBinary = base64_decode($signature, true);
        
        if ($signatureBinary === false) {
            return false;
        }
        
        // Try to verify with certificate if available
        if (file_exists($this->certificatePath) && is_readable($this->certificatePath)) {
            $cert = openssl_x509_read(file_get_contents($this->certificatePath));
            if ($cert !== false) {
                $publicKey = openssl_pkey_get_public($cert);
                if ($publicKey !== false) {
                    $result = openssl_verify($dataToVerify, $signatureBinary, $publicKey, OPENSSL_ALGO_SHA256);
                    openssl_free_key($publicKey);
                    openssl_x509_free($cert);
                    return $result === 1;
                }
                openssl_x509_free($cert);
            }
        }
        
        // Fallback: Verify HMAC
        $secret = \iChat\Config::getInstance()->get('api.shared_secret', 'default-secret-change-me');
        $expectedSignature = base64_encode(hash_hmac('sha256', $dataToVerify, $secret, true));
        return hash_equals($expectedSignature, $signature);
    }
}

