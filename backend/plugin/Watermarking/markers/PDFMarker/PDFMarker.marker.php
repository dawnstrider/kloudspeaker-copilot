<?php

/**
 * PDFMarker.marker.php
 *
 * Copyright 2015- Samuli Järvelä
 * Released under GPL license.
 *
 * License: http://www.kloudspeaker.com/license.php
 */

$pdfVendor = dirname(__DIR__, 4) . '/vendor/';
require_once $pdfVendor . 'fpdf/fpdf.php';
require_once $pdfVendor . 'fpdi/src/autoload.php';

class PDFMarker extends MarkerBase {

	public function mark($src, $dest, $watermarkText) {
		if (!is_string($src) or !is_string($dest) or !is_string($watermarkText) or $dest === '' or !is_file($src) or !is_readable($src)) {
			throw new ServiceException("INVALID_REQUEST", "A readable PDF source and destination file are required");
		}
		if ($src === $dest or (file_exists($dest) and realpath($src) === realpath($dest)) or is_dir($dest)) {
			throw new ServiceException("INVALID_REQUEST", "PDF destination must differ from its source");
		}
		if (!is_dir(dirname($dest)) or !is_writable(dirname($dest))) {
			throw new ServiceException("REQUEST_FAILED", "PDF destination directory is not writable");
		}
		$temporaryImage = NULL;
		$temporaryPdf = NULL;
		try {
			$imgProp = NULL;
			if ($this->getSetting("img_file", NULL) != NULL) {
				$file = $this->getSetting("img_file");
				if (!is_string($file) or !is_file($file) or !is_readable($file)) {
					throw new ServiceException("INVALID_CONFIGURATION", "Configured watermark image is not readable");
				}
				try {
					$size = @getimagesize($file);
				} catch (Exception $exception) {
					throw new ServiceException("INVALID_CONFIGURATION", "Configured watermark image is invalid");
				}
				if ($size === FALSE) throw new ServiceException("INVALID_CONFIGURATION", "Configured watermark image is invalid");
				$imgProp = $this->extend_props($watermarkText, array("size" => $size, "file" => $file));
			} else if ($this->getSetting("rotation", NULL) != NULL) {
				$font = $this->getSetting("img_font", __DIR__ . '/Arial.ttf');
				if (!is_string($font) or !is_file($font) or !is_readable($font)) {
					throw new ServiceException("INVALID_CONFIGURATION", "Image generation requires a readable 'img_font'");
				}
				$imgProp = $this->extend_props($watermarkText, array(
					"size" => $this->getSetting("img_size", array(800, 200)),
					"font" => $font,
					"font_size" => $this->getSetting("img_font_size", 50),
					"color" => $this->getSetting("color", array(0, 0, 0)),
					"alpha" => $this->getSetting("alpha", 50),
					"rotation" => $this->getSetting("rotation", 45),
				));
				$temporaryImage = $this->env->imageGenerator()->createText($watermarkText, $imgProp["size"], $imgProp["font"], $imgProp["font_size"], $imgProp["color"], $imgProp["alpha"], $imgProp["rotation"]);
				$imgProp["file"] = $temporaryImage;
				$imgProp["size"] = getimagesize($temporaryImage);
			}
			$pdf = new WatermarkedPDF($this->getProperties($src, $watermarkText, $imgProp));
			$temporaryPdf = tempnam(dirname($dest), 'kloudspeaker-pdf-');
			if ($temporaryPdf === FALSE) throw new ServiceException("REQUEST_FAILED", "Could not create temporary PDF");
			$pdf->mark($temporaryPdf);
			if (!rename($temporaryPdf, $dest)) throw new ServiceException("REQUEST_FAILED", "Could not finalize watermarked PDF");
			return TRUE;
		} catch (ServiceException $exception) {
			throw $exception;
		} catch (Exception $exception) {
			throw new ServiceException("REQUEST_FAILED", "Could not watermark PDF");
		} finally {
			if ($temporaryPdf !== NULL and is_file($temporaryPdf)) unlink($temporaryPdf);
			if ($temporaryImage !== NULL and is_file($temporaryImage)) unlink($temporaryImage);
		}
	}

	private function extend_props($watermarkText, $props) {
		if (function_exists("get_watermark_props")) {
			$result = get_watermark_props($watermarkText, $props);
			if ($result != NULL) {
				foreach ($props as $key => $value) {
					if (array_key_exists($key, $result)) $props[$key] = $result[$key];
				}
			}
		}
		return $props;
	}

	private function getProperties($src, $text, $imgProp = NULL) {
		return $this->extend_props($text, array(
			"src" => $src,
			"text" => $text,
			"img" => $imgProp,
			"font" => $this->getSetting("font", array("Arial", "B", 26)),
			"color" => $this->getSetting("color", array(0, 0, 0)),
			"position" => $this->getSetting("position", array("center", "center")),
			"height" => $this->getSetting("height", 10),
			"bottomOffset" => $this->getSetting("bottomOffset", -30),
			"margins" => $this->getSetting("margins", array(0, 0)),
		));
	}
}

class WatermarkedPDF extends \setasign\Fpdi\Fpdi {
	private $opt;
	private $xd = 0;
	private $yd = 0;
	private $wd = 0;
	private $align = "C";
	private $pageSize;

	function __construct($opt) {
		parent::__construct();
		$this->SetAutoPageBreak(FALSE, 0);
		$this->opt = $opt;
	}

	public function mark($dest) {
		$pages = $this->setSourceFile($this->opt["src"]);

		for ($i = 1; $i <= $pages; $i++) {
			$template = $this->importPage($i);
			$size = $this->getTemplateSize($template);

			$this->addPage($size['orientation'], array($size['width'], $size['height']));
			$this->preparePage(array($size['width'], $size['height']));
			$this->useTemplate($template);

			if (is_array($this->opt["margins"])) {
				$this->setMargins($this->opt["margins"][0], $this->opt["margins"][1]);
			}

			if (!$this->isInFooter()) {
				$this->markBody();
			}
		}

		$this->Output('F', $dest);
	}

	private function preparePage($size) {
		//TODO can pages be different size? otherwise could be done only once

		$this->pageSize = $size;

		// horizontal
		$hp = $this->opt["position"][0];
		if (strcasecmp($hp, 'left') == 0) {
			$this->align = "L";
		} else if (strcasecmp($hp, 'right') == 0) {
			$this->align = "R";
		} else if (strcasecmp($hp, 'center') != 0) {
			if (is_numeric($hp)) {
				$this->xd = $hp;
				if ($this->xd < 0) {
					$this->wd = $size[0] + $this->xd; // width until x mm from right
					$this->xd = 0;
					$this->align = "R";
				} else {
					$this->align = "X";
				}
			}
		}

		//vertical
		$vp = $this->opt["position"][1];
		if (strcasecmp($vp, 'center') == 0) {
			$this->yd = ($size[1] / 2) - $this->opt["height"];
		} else if (strcasecmp($vp, 'top') == 0) {
			$this->yd = 0;
		} else if (strcasecmp($vp, 'bottom') == 0) {
			$this->yd = $this->opt["bottomOffset"];
		} else {
			if (is_numeric($vp)) {
				$this->yd = $vp;
				if ($this->yd < 0) {
					$this->yd = $this->opt["bottomOffset"] + $this->yd;
				}
			}
		}
	}

	private function isInFooter() {
		return (!$this->isImage() and strcasecmp($this->opt["position"][1], 'bottom') == 0);
	}

	private function isImage() {
		return (isset($this->opt["img"]) and $this->opt["img"] != NULL);
	}

	// called manually
	private function markBody() {
		if ($this->isImage()) {
			//$iw = ($this->opt["img"]["size"][0] / 96) * 25.4;//in mm
			//$ih = ($this->opt["img"]["size"][1] / 96) * 25.4;//in mm
			//$x = ($this->pageSize[0]-$iw) / 2;
			//$y = ($this->pageSize[1]-$ih) / 2;
			$x = 0;
			$y = ($this->pageSize[1] - $this->pageSize[0]) / 2;
			$this->Image($this->opt["img"]["file"], $x, $y, $this->pageSize[0]);
			return;
		}

		if (Logging::isDebug()) {
			Logging::logDebug("Body " . Util::array2str(array("x" => $this->xd, "y" => $this->yd, "w" => $this->wd, "align" => $this->align, "opt" => $this->opt)));
		}
		$this->setTextProperties();

		if ($this->align == "X") {
			$this->Text($this->xd, $this->yd, $this->opt["text"]);
		} else {
			$this->setXY($this->xd, $this->yd);
			$this->Cell($this->wd, $this->opt["height"], $this->opt["text"], 0, 0, $this->align);
		}
	}

	private function setTextProperties() {
		$this->SetFont($this->opt["font"][0], $this->opt["font"][1], $this->opt["font"][2]);
		$this->SetTextColor($this->opt["color"][0], $this->opt["color"][1], $this->opt["color"][2]);

	}

	// called automatically by FPDI from AddPage
	function Footer() {
		if (!$this->isInFooter()) {
			return;
		}

		$y = 0 - $this->opt["height"];
		if (Logging::isDebug()) {
			Logging::logDebug("Footer " . Util::array2str(array("x" => $this->xd, "y" => $y, "w" => $this->wd, "align" => $this->align, "opt" => $this->opt)));
		}

		$this->setTextProperties();
		$this->setXY($this->xd, $y);
		$this->Cell($this->wd, $this->opt["height"], $this->opt["text"], 0, 0, ($this->align == "X" ? "L" : $this->align));
	}
}

?>