<?php

namespace AutoCatUploads;

class Hooks {
	/**
	 * Automatically inject Category wikimarkup onto description pages
	 * of new uploads based on file metadata.
	 * 
	 * @param \WikiPage $wikiPage The page being edited
	 * @param \User $user The user editing the page
	 * @param \Content $content Page content
	 * @param string $summary Edit summary
	 * @param boolean $isMinor Whether or not this is a minor edit
	 * @param null $isWatch Unused, always null
	 * @param null $section Unused, always null
	 * @param int $flags Bitfield of EDIT_* constants
	 * @param \Status $status Status of page save
	 * @return boolean Returns true to continue hook processing
	 */
	public static function onPageContentSave(
		\WikiPage &$wikiPage, \User &$user, \Content &$content, &$summary,
		$isMinor, $isWatch, $section, &$flags, \Status &$status
	) {
		// we only care about file upload description pages,
		// which are always instances of WikiFilePage.
		if ( !( $wikiPage instanceof \WikiFilePage ) ) {
			return true;
		}

		// if this is a re-upload, we don't want to mess with categorisation.
		if ( !( $flags & \EDIT_NEW ) ) {
			return true;
		}

		// if the page isn't wikitext, bail out
		if ( $content->getModel() !== \CONTENT_MODEL_WIKITEXT ) {
			return true;
		}

		$file = $wikiPage->getFile();
		if ( $file === false || !$file->exists() ) {
			return true;
		}

		$keywords = self::getKeywords( $file );
		if ( $keywords === [] ) {
			return true;
		}

		$categoryText = '';
		foreach ( $keywords as $potentialCat ) {
			$title = \Title::makeTitleSafe( \NS_CATEGORY, $potentialCat );

			// don't make cats for invalid titles or titles which happen
			// to include namespace names or interwiki.
			if (
				$title === null
				|| $title->getNamespace() !== \NS_CATEGORY
				|| $title->isExternal()
			) {
				continue;
			}

			$categoryText .= "\n[[" . $title->getPrefixedText() . ']]';
		}

		if ( $categoryText === '' ) {
			return true;
		}

		$text = $content->getNativeData()
			. "\n" . wfMessage( 'autocatuploads-comment' )->plain()
			. $categoryText;
		$content = new \WikitextContent( $text );

		return true;
	}

	/**
	 * Get the keywords from file metadata. We parse XMP, ITCP (for JPG),
	 * and ID3 (for MP3).
	 * 
	 * @param \File $file The file to get keywords for
	 * @return array Array of keywords
	 */
	private static function getKeywords( $file ) {
		$fsfile = $file->getRepo()->getLocalReference( $file->getPath() );
		$ext = $file->getExtension();
		$path = $fsfile->getPath();

		$xmp = self::parseXMP( $path );
		$itcp = [];
		$id3 = [];

		if ( $ext === 'jpg' ) {
			$itcp = self::parseITCP( $path );
		}

		if ( $ext === 'mp3' ) {
			$id3 = self::parseID3( $path );
		}

		return array_unique( array_merge( $xmp, $itcp, $id3 ) );
	}

	/**
	 * Parse XMP metadata from a file
	 * 
	 * @param string $fname The file to process
	 * @return array Array of keywords
	 */
	private static function parseXMP( $fname ) {
		// check for XMP metadata
		$f = fopen( $fname, 'rb' );
		$tail = '';
		$xmp = '';
		$xmpState = 0;
	
		/* XMP States
		 * 0 = look for beginning tag
		 * 1 = beginning tag found in this chunk (don't strip $tail)
		 * 2 = beginning tag found but not in this chunk (strip $tail)
		 * 3 = beginning and end tag found in same chunk (don't strip $tail)
		 * 4 = end tag found, not in same chunk as beginning (strip $tail)
		 */
		while ( !feof( $f ) ) {
			$data = $tail . fread( $f, 4096 );
	
			if ( $xmpState === 0 ) {
				// looking for beginning tag
				$spos = strpos( $data, '<x:xmpmeta' );
				if ( $spos !== false ) {
					$xmpState = 1;
					$data = substr( $data, $spos );
				}
			}
			
			if ( $xmpState === 1 || $xmpState === 2 ) {
				$epos = strpos( $data, '</x:xmpmeta>' );
				if ( $epos !== false ) {
					$xmpState += 2;
					$data = substr( $data, 0, $epos + 12 );
				}
	
				if ( $xmpState === 1 || $xmpState === 3 ) {
					$xmp .= $data;
				} else {
					// don't duplicate $tail
					$xmp .= substr( $data, strlen( $tail ) );
				}
			}
	
			if ( $xmpState === 1 ) {
				$xmpState = 2;
			} elseif ( $xmpState === 3 || $xmpState === 4 ) {
				break;
			}
	
			// catch tags split across chunk boundaries by overlapping buffer
			$tail = substr( $data, -16 );
		}
	
		fclose( $f );
	
		if ( $xmp === '' ) {
			// no XMP data found
			return [];
		}

		$parsed = new \SimpleXMLElement( $xmp );
		$parsed->registerXPathNamespace(
			'dc',
			'http://purl.org/dc/elements/1.1/' );
		$subject = $parsed->xpath('//dc:subject');
		$keywords = [];

		if ( $subject !== false && $subject !== [] ) {
			// check if this is an rdf:bag or if it's just a text node
			$subject = $subject[0];
			$subject->registerXPathNamespace(
				'rdf',
				'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );
			$lis = $subject->xpath('.//rdf:li');

			if ( $lis !== false ) {
				foreach ( $lis as $li ) {
					$keywords[] = trim( $li->__toString() );
				}
			} else {
				$strdata = trim( $subject->__toString() );
				$keywords = self::splitKeywordString( $strdata );
			}
		}

		return $keywords;
	}

	/**
	 * Parse ITCP metdata for a JPG image.
	 * 
	 * @param string $fname File path
	 * @return array Array of keywords
	 */
	private static function parseITCP( $fname ) {
		getimagesize( $fname, $info );
		// APP13 is ITPC, and inside of that the key 2#025 is Keywords
		if ( array_key_exists( 'APP13', $info ) ) {
			$metadata = iptcparse( $info['APP13'] );
			if ( array_key_exists( '2#025', $metadata ) ) {
				return $metadata['2#025'];
			}
		}

		return [];
	}

	/**
	 * Parse ID3 metadata for an MP3 file.
	 * 
	 * @param string $fname File path
	 * @return array Array of keywords
	 */
	private static function parseID3( $fname ) {
		$f = fopen( $fname, 'rb' );
		$hdr = fread( $f, 10 );

		// read id string, major version, minor version, flags, and size
		$hdr = unpack( 'a3id/Cmaj/Cmin/Cflags/Nsz', $hdr );

		// only support versions 2.2.X-2.4.X
		if ( $hdr['id'] !== 'ID3' || $hdr['maj'] < 2 || $hdr['maj'] > 4 ) {
			fclose( $f );
			return [];
		}
		
		// Note: v2.4.0 also allows appended tags where the header doesn't
		// appear at the beginning, or data is split between header and a
		// tag later on (using a "footer" with 3DI identifier). We don't
		// support that as it doesn't see much real-world use right now.
		$hdr['sz'] = self::processSyncsafeInt( $hdr['sz'] );
		$needUnsync = ( $hdr['flags'] & 0x80 ) === 0x80;
		$buf = fread( $f, $hdr['sz'] );

		// check for footer (unused for now)
		$foot = '';
		if ( $hdr['flags'] & 0x10 ) {
			$foot = fread( $f, 10 );
		}

		fclose( $f );

		// run unsyc algorithm if needed
		if ( $needUnsync ) {
			$buf = str_replace( "\xff\x00", "\xff", $buf );
		}

		// skip over an extended header (versions 3 and 4 only)
		if ( $hdr['flags'] & 0x40 ) {
			$ehsize = unpack( 'Nsz', self::bufread( $buf, 4 ) );
			$ehsize = self::processSyncsafeInt( $ehsize['sz'] );
			if ( $hdr['maj'] === 3 ) {
				// $ehsize does not include the 4 bytes we just read
				self::bufread( $buf, $ehsize );
			} elseif ( $hdr['maj'] === 4 ) {
				// $ehsize includes the 4 bytes we just read
				self::bufread( $buf, $ehsize - 4 );
			}
		}

		// start processing frames looking for COMM (or COM in v2)
		// if it isn't COMM, just skip over it; we don't care about it
		while ( $buf !== '' ) {
			$compressed = false;

			if ( $hdr['maj'] === 2 ) {
				// 3 byte tag name and 3 byte size
				$th = unpack( 'a3id/Cb1/Cb2/Cb3', self::bufread( $buf, 6 ) );
				$tsize = self::combine( $th['b1'], $th['b2'], $th['b3'] );
			} elseif ( $hdr['maj'] === 3 ) {
				// 4 byte tag name, 4 byte size, and 2 byte flags
				$th = unpack( 'a4id/Nsz/nflags', self::bufread( $buf, 10 ) );
				$tsize = self::processSyncsafeInt( $th['sz'] );
				if ( $th['flags'] & 0x0080 ) {
					$compressed = true;
					// next 4 bytes are the decompressed size; skip them
					self::bufread( $buf, 4 );
				}

				if ( $th['flags'] & 0x0020 ) {
					// tag is grouped
					// not supported here but it adds an extra byte to discard
					self::bufread( $buf, 1 );
				}

				if ( $th['flags'] & 0x0040 ) {
					// tag is encrypted, and we don't support that
					self::bufread( $buf, $tsize + 1 );
					continue;
				}
			} else {
				// 4 byte tag name, 4 byte size, and 2 byte flags
				$th = unpack( 'a4id/Nsz/nflags', self::bufread( $buf, 10 ) );
				$tsize = self::processSyncsafeInt( $th['sz'] );

				if ( $th['flags'] & 0x0040 ) {
					// grouped
					self::bufread( $buf, 1 );
				}

				if ( $th['flags'] & 0x0008 ) {
					// compressed
					$compressed = true;
				}

				if ( $th['flags'] & 0x0001 ) {
					// data length was added (4 bytes we don't care about)
					self::bufread( $buf, 4 );
				}

				if ( $th['flags'] & 0x0004 ) {
					// encrypted -- not supported but adds an extra byte
					self::bufread( $buf, $tsize + 1 );
					continue;
				}
			}

			if ( $compressed ) {
				$idata = gzinflate( self::bufread( $buf, $tsize ) );
			} else {
				$idata = &$buf;
			}

			if ( $th['id'] !== 'COM' && $th['id'] !== 'COMM' ) {
				self::bufread( $buf, $tsize );
				continue;
			}

			$enc = self::bufread( $idata, 1 );
			// 3 byte language code; we don't process this
			self::bufread( $idata, 3 );

			if ( $compressed ) {
				$strings = $idata;
			} else {
				$strings = self::bufread( $idata, $tsize - 4 );
			}

			switch ( $enc ) {
				case 0:
					$tenc = 'ISO-8859-1';
					$split = "\x00";
					break;
				case 1:
					if ( $hdr['maj'] === 4 ) {
						$tenc = 'UTF-16';
					} else {
						$tenc = 'UCS-2';
					}

					$split = "\x00\x00";
					break;
				case 2:
					$tenc = 'UTF-16BE';
					$split = "\x00\x00";
					break;
				case 3:
					$tenc = 'UTF-8';
					$split = "\x00";
					break;
				default:
					// invalid encoding
					return [];
			}

			list( $desc, $comm ) = explode( $split, $strings, 2 );
			$desc = trim( mb_convert_encoding( $desc, 'UTF-8', $tenc ) );

			// We might have multiple comment tags, each with a distinct $desc
			// The one with the empty desc is the keywords, all others
			// typically contain app-specific metadata.
			if ( $desc === '' ) {
				$comm = trim( mb_convert_encoding( $comm, 'UTF-8', $tenc ) );
				return self::splitKeywordString( $comm );
			}
		}

		// got to end of tags without finding a comment with blank description
		return [];
	}

	/**
	 * Splits a comma or semicolon separated list of keywords into an array.
	 * 
	 * @param string $strdata String to split
	 * @return array Array of keywords
	 */
	private static function splitKeywordString( $strdata ) {
		$keywords = [];

		if ( strpos( $strdata, ';' ) !== false ) {
			$keywords = explode( ';', $strdata );
			foreach ( $keywords as &$key ) {
				$key = trim( $key );
			}
		} elseif ( $strdata !== '' ) {
			$keywords = explode( ',', $strdata );
			foreach ( $keywords as &$key ) {
				$key = trim( $key );
			}
		}

		return $keywords;
	}

	/**
	 * Converts a 4-byte array where only the lower 7 bits of each byte are used
	 * into a 28-byte integer. The ID3 encoding makes extensive use of these.
	 * 
	 * @param int $i The 4-byte array
	 * @return int The processed 28-bit int
	 */
	private static function processSyncsafeInt( $i ) {
		$p1 = $i & 0x0000007f;
		$p2 = ($i & 0x00007f00) >> 1;
		$p3 = ($i & 0x007f0000) >> 2;
		$p4 = ($i & 0x7f000000) >> 3;

		return $p1 | $p2 | $p3 | $p4;
	}

	/**
	 * Combines 3 bytes into a 4-byte int.
	 * 
	 * @param int $b1 The first (most significant) byte.
	 * @param int $b2 The second byte.
	 * @param int $b3 The third (least significant) byte.
	 * @return int
	 */
	private static function combine( $b1, $b2, $b3 ) {
		return ( $b1 << 16 ) | ( $b2 << 8 ) | $b3;
	}

	/**
	 * Reads a number of bytes from the buffer and advances the buffer.
	 * 
	 * @param string $buf The buffer to read from
	 * @param int $length The number of bytes to read
	 * @return string Up to $length bytes read from the beginning of $buf.
	 */
	private static function bufread( &$buf, $length ) {
		$str = substr( $buf, 0, $length );
		$buf = substr( $buf, $length );
	
		return $str;
	}
}
