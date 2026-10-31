<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Read filter of NuSpreadsheet::read (0.8.755): only the first rows / columns are loaded. Required after the PhpSpreadsheet pack is loaded. */
final class NuSpreadsheetFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
{
	public function __construct(private int $rows, private int $cols) {}

	public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
	{
		return $row <= $this->rows && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->cols;
	}
}
