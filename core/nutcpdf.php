<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** TCPDF without the "Powered by TCPDF" link on the last page (loaded by NuReport::loadTcpdf() after tcpdf.php). */
final class NuTCPDF extends TCPDF
{
	public function __construct(...$args)
	{
		parent::__construct(...$args);
		if (property_exists($this, 'tcpdflink')) $this->tcpdflink = false;    // TCPDF 6 only (7 has no such link)
	}
}
