<?php

namespace App\modules\bookingfrontend\models;

use App\modules\phpgwapi\models\ServerSettings;
use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *      schema="Document",
 *      type="object",
 *      title="Document",
 *      description="Document model representing images and other files",
 * )
 * @Exclude
 */
class Document
{
    use SerializableTrait;

    public const CATEGORY_PICTURE = 'picture';
    public const CATEGORY_REGULATION = 'regulation';
    public const CATEGORY_HMS_DOCUMENT = 'HMS_document';
    public const CATEGORY_PICTURE_MAIN = 'picture_main';
    public const CATEGORY_DRAWING = 'drawing';
    public const CATEGORY_PRICE_LIST = 'price_list';
    public const CATEGORY_OTHER = 'other';
    public const OWNER_BUILDING = 'building';
    public const OWNER_APPLICATION = 'application';
    public const OWNER_RESOURCE = 'resource';


    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $id;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $name;

    /**
     * @OA\Property(type="string")
     * @Expose
     */
    public $description;

    /**
     * @OA\Property(type="string", enum={"picture", "regulation", "HMS_document", "picture_main", "drawing", "price_list", "other"})
     * @Expose
     */
    public $category;

    /**
     * @OA\Property(type="integer")
     * @Expose
     */
    public $owner_id;

    /**
     * @OA\Property(type="string", enum={"building", "resource", "application"})
     * @Exclude
     */
    public $owner_type;


    public function __construct(array $data, string $owner_type = null)
    {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->category = $data['category'] ?? '';
        $this->owner_id = $data['owner_id'] ?? null;
        $this->owner_type = $owner_type ?? Document::OWNER_BUILDING;
    }

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_PICTURE,
            self::CATEGORY_REGULATION,
            self::CATEGORY_HMS_DOCUMENT,
            self::CATEGORY_PICTURE_MAIN,
            self::CATEGORY_DRAWING,
            self::CATEGORY_PRICE_LIST,
            self::CATEGORY_OTHER,
        ];
    }

    public function generate_filename()
    {
        return $this->get_files_path() . DIRECTORY_SEPARATOR . $this->id . '_' . $this->name;
    }


    private function get_files_path()
    {
        $serverSettings = ServerSettings::getInstance();
        return $serverSettings->files_dir . DIRECTORY_SEPARATOR . 'booking' . DIRECTORY_SEPARATOR . $this->owner_type;
    }

    public function getFileTypeFromExtension(): string
    {
        $extension = pathinfo($this->name, PATHINFO_EXTENSION);
        $mimeTypes = [
            'ez' => 'application/andrew-inset',
            'base64' => 'application/x-word',
            'dp' => 'application/commonground',
            'pqi' => 'application/cprplayer',
            'tsp' => 'application/dsptype',
            'xls' => 'application/x-msexcel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pfr' => 'application/font-tdpfr',
            'spl' => 'application/x-futuresplash',
            'stk' => 'application/hyperstudio',
            'js' => 'application/x-javascript',
            'hqx' => 'application/mac-binhex40',
            'cpt' => 'application/x-mac-compactpro',
            'mbd' => 'application/mbed',
            'mfp' => 'application/mirage',
            'doc' => 'application/x-msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'orq' => 'application/ocsp-request',
            'ors' => 'application/ocsp-response',
            'bin' => 'application/octet-stream',
            'oda' => 'application/oda',
            'ogg' => 'application/ogg',
            'pdf' => 'application/x-pdf',
            '7bit' => 'application/pgp-keys',
            'sig' => 'application/pgp-signature',
            'p10' => 'application/pkcs10',
            'p7m' => 'application/pkcs7-mime',
            'p7s' => 'application/pkcs7-signature',
            'cer' => 'application/pkix-cert',
            'crl' => 'application/pkix-crl',
            'pkipath' => 'application/pkix-pkipath',
            'pki' => 'application/pkixcmp',
            'ps' => 'application/postscript',
            'shw' => 'application/presentations',
            'cw' => 'application/prs.cww',
            'rnd' => 'application/prs.nprend',
            'qrt' => 'application/quest',
            'rtf' => 'text/rtf',
            'soc' => 'application/sgml-open-catalog',
            'siv' => 'application/sieve',
            'smi' => 'application/smil',
            'tbk' => 'application/toolbook',
            'plb' => 'application/vnd.3gpp.pic-bw-large',
            'psb' => 'application/vnd.3gpp.pic-bw-small',
            'pvb' => 'application/vnd.3gpp.pic-bw-var',
            'sms' => 'application/vnd.3gpp.sms',
            'atc' => 'application/vnd.acucorp',
            'xfdf' => 'application/vnd.adobe.xfdf',
            'ami' => 'application/vnd.amiga.amu',
            'mpm' => 'application/vnd.blueice.multipass',
            'cdy' => 'application/vnd.cinderella',
            'cmc' => 'application/vnd.cosmocaller',
            'wbs' => 'application/vnd.criticaltools.wbs+xml',
            'curl' => 'application/vnd.curl',
            'rdz' => 'application/vnd.data-vision.rdz',
            'dfac' => 'application/vnd.dreamfactory',
            'fsc' => 'application/vnd.fsc.weblauch',
            'txd' => 'application/vnd.genomatix.tuxedo',
            'hbci' => 'application/vnd.hbci',
            'les' => 'application/vnd.hhe.lesson-player',
            'plt' => 'application/vnd.hp-hpgl',
            'emm' => 'application/vnd.ibm.electronic-media',
            'irm' => 'application/vnd.ibm.rights-management',
            'sc' => 'application/vnd.ibm.secure-container',
            'rcprofile' => 'application/vnd.ipunplugged.rcprofile',
            'irp' => 'application/vnd.irepository.package+xml',
            'jisp' => 'application/vnd.jisp',
            'karbon' => 'application/vnd.kde.karbon',
            'chrt' => 'application/vnd.kde.kchart',
            'kfo' => 'application/vnd.kde.kformula',
            'flw' => 'application/vnd.kde.kivio',
            'kon' => 'application/vnd.kde.kontour',
            'kpr' => 'application/vnd.kde.kpresenter',
            'ksp' => 'application/vnd.kde.kspread',
            'kwd' => 'application/vnd.kde.kword',
            'htke' => 'application/vnd.kenameapp',
            'kia' => 'application/vnd.kidspiration',
            'kne' => 'application/vnd.kinar',
            'lbd' => 'application/vnd.llamagraphics.life-balance.desktop',
            'lbe' => 'application/vnd.llamagraphics.life-balance.exchange+xml',
            'wks' => 'application/vnd.lotus-1-2-3',
            'mcd' => 'application/x-mathcad',
            'mfm' => 'application/vnd.mfmp',
            'flo' => 'application/vnd.micrografx.flo',
            'igx' => 'application/vnd.micrografx.igx',
            'mif' => 'application/x-mif',
            'mpn' => 'application/vnd.mophun.application',
            'mpc' => 'application/vnd.mophun.certificate',
            'xul' => 'application/vnd.mozilla.xul+xml',
            'cil' => 'application/vnd.ms-artgalry',
            'asf' => 'video/x-ms-asf',
            'lrm' => 'application/vnd.ms-lrm',
            'ppt' => 'application/vnd.ms-powerpoint',
            'mpp' => 'application/vnd.ms-project',
            'wpl' => 'application/vnd.ms-wpl',
            'mseq' => 'application/vnd.mseq',
            'ent' => 'application/vnd.nervana',
            'rpst' => 'application/vnd.nokia.radio-preset',
            'rpss' => 'application/vnd.nokia.radio-presets',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ott' => 'application/vnd.oasis.opendocument.text-template',
            'oth' => 'application/vnd.oasis.opendocument.text-web',
            'odm' => 'application/vnd.oasis.opendocument.text-master',
            'odg' => 'application/vnd.oasis.opendocument.graphics',
            'otg' => 'application/vnd.oasis.opendocument.graphics-template',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'otp' => 'application/vnd.oasis.opendocument.presentation-template',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'ots' => 'application/vnd.oasis.opendocument.spreadsheet-template',
            'odc' => 'application/vnd.oasis.opendocument.chart',
            'odf' => 'application/vnd.oasis.opendocument.formula',
            'odb' => 'application/vnd.oasis.opendocument.database',
            'odi' => 'application/vnd.oasis.opendocument.image',
            'prc' => 'application/vnd.palm',
            'efif' => 'application/vnd.picsel',
            'pti' => 'application/vnd.pvi.ptid1',
            'qxd' => 'application/vnd.quark.quarkxpress',
            'sdoc' => 'application/vnd.sealed.doc',
            'seml' => 'application/vnd.sealed.eml',
            'smht' => 'application/vnd.sealed.mht',
            'sppt' => 'application/vnd.sealed.ppt',
            'sxls' => 'application/vnd.sealed.xls',
            'stml' => 'application/vnd.sealedmedia.softseal.html',
            'spdf' => 'application/vnd.sealedmedia.softseal.pdf',
            'see' => 'application/vnd.seemail',
            'mmf' => 'application/vnd.smaf',
            'sxc' => 'application/vnd.sun.xml.calc',
            'stc' => 'application/vnd.sun.xml.calc.template',
            'sxd' => 'application/vnd.sun.xml.draw',
            'std' => 'application/vnd.sun.xml.draw.template',
            'sxi' => 'application/vnd.sun.xml.impress',
            'sti' => 'application/vnd.sun.xml.impress.template',
            'sxm' => 'application/vnd.sun.xml.math',
            'sxw' => 'application/vnd.sun.xml.writer',
            'sxg' => 'application/vnd.sun.xml.writer.global',
            'stw' => 'application/vnd.sun.xml.writer.template',
            'sus' => 'application/vnd.sus-calendar',
            'vsc' => 'application/vnd.vidsoft.vidconference',
            'vsd' => 'application/vnd.visio',
            'vis' => 'application/vnd.visionary',
            'sic' => 'application/vnd.wap.sic',
            'slc' => 'application/vnd.wap.slc',
            'wbxml' => 'application/vnd.wap.wbxml',
            'wmlc' => 'application/vnd.wap.wmlc',
            'wmlsc' => 'application/vnd.wap.wmlscriptc',
            'wtb' => 'application/vnd.webturbo',
            'wpd' => 'application/vnd.wordperfect',
            'wqd' => 'application/vnd.wqd',
            'wv' => 'application/vnd.wv.csp+wbxml',
            '8bit' => 'multipart/parallel',
            'hvd' => 'application/vnd.yamaha.hv-dic',
            'hvs' => 'application/vnd.yamaha.hv-script',
            'hvp' => 'application/vnd.yamaha.hv-voice',
            'saf' => 'application/vnd.yamaha.smaf-audio',
            'spf' => 'application/vnd.yamaha.smaf-phrase',
            'vmd' => 'application/vocaltec-media-desc',
            'vmf' => 'application/vocaltec-media-file',
            'vtk' => 'application/vocaltec-talker',
            'wif' => 'image/cewavelet',
            'wp5' => 'application/wordperfect5.1',
            'wk' => 'application/x-123',
            '7ls' => 'application/x-7th_level_event',
            'aab' => 'application/x-authorware-bin',
            'aam' => 'application/x-authorware-map',
            'aas' => 'application/x-authorware-seg',
            'bcpio' => 'application/x-bcpio',
            'bleep' => 'application/x-bleeper',
            'bz2' => 'application/x-bzip2',
            'vcd' => 'application/x-cdlink',
            'chat' => 'application/x-chat',
            'pgn' => 'application/x-chess-pgn',
            'z' => 'application/x-compress',
            'cpio' => 'application/x-cpio',
            'pqf' => 'application/x-cprplayer',
            'csh' => 'application/x-csh',
            'csm' => 'chemical/x-csml',
            'co' => 'application/x-cult3d-object',
            'deb' => 'application/x-debian-package',
            'dcr' => 'application/x-director',
            'dvi' => 'application/x-dvi',
            'evy' => 'application/x-envoy',
            'gtar' => 'application/x-gtar',
            'gz' => 'application/x-gzip',
            'hdf' => 'application/x-hdf',
            'hep' => 'application/x-hep',
            'rhtml' => 'application/x-html+ruby',
            'mv' => 'application/x-httpd-miva',
            'phtml' => 'application/x-httpd-php',
            'ica' => 'application/x-ica',
            'imagemap' => 'application/x-imagemap',
            'ipx' => 'application/x-ipix',
            'ips' => 'application/x-ipscript',
            'jar' => 'application/x-java-archive',
            'jnlp' => 'application/x-java-jnlp-file',
            'ser' => 'application/x-java-serialized-object',
            'class' => 'application/x-java-vm',
            'skp' => 'application/x-koan',
            'latex' => 'application/x-latex',
            'frm' => 'application/x-maker',
            'mid' => 'audio/x-midi',
            'mda' => 'application/x-msaccess',
            'com' => 'application/x-msdos-program',
            'nc' => 'application/x-netcdf',
            'pac' => 'application/x-ns-proxy-autoconfig',
            'pm5' => 'application/x-pagemaker',
            'pl' => 'application/x-perl',
            'rp' => 'application/x-pn-realmedia',
            'py' => 'application/x-python',
            'qtl' => 'application/x-quicktimeplayer',
            'rar' => 'application/x-rar-compressed',
            'rb' => 'application/x-ruby',
            'sh' => 'application/x-sh',
            'shar' => 'application/x-shar',
            'swf' => 'application/x-shockwave-flash',
            'spr' => 'application/x-sprite',
            'sav' => 'application/x-spss',
            'spt' => 'application/x-spt',
            'sit' => 'application/x-stuffit',
            'sv4cpio' => 'application/x-sv4cpio',
            'sv4crc' => 'application/x-sv4crc',
            'tar' => 'application/x-tar',
            'tcl' => 'application/x-tcl',
            'tex' => 'application/x-tex',
            'texinfo' => 'application/x-texinfo',
            't' => 'application/x-troff',
            'man' => 'application/x-troff-man',
            'me' => 'application/x-troff-me',
            'ms' => 'application/x-troff-ms',
            'vqf' => 'application/x-twinvq',
            'vqe' => 'application/x-twinvq-plugin',
            'ustar' => 'application/x-ustar',
            'bck' => 'application/x-vmsbackup',
            'src' => 'application/x-wais-source',
            'wz' => 'application/x-wingz',
            'wp6' => 'application/x-wordperfect6.1',
            'crt' => 'application/x-x509-ca-cert',
            'zip' => 'application/zip',
            'xhtml' => 'application/xhtml+xml',
            '3gpp' => 'audio/3gpp',
            'amr' => 'audio/amr',
            'awb' => 'audio/amr-wb',
            'au' => 'audio/basic',
            'evc' => 'audio/evrc',
            'l16' => 'audio/l16',
            'mp3' => 'audio/mpeg',
            'sid' => 'audio/prs.sid',
            'qcp' => 'audio/qcelp',
            'smv' => 'audio/smv',
            'koz' => 'audio/vnd.audiokoz',
            'eol' => 'audio/vnd.digital-winds',
            'plj' => 'audio/vnd.everad.plj',
            'lvp' => 'audio/vnd.lucent.voice',
            'mxmf' => 'audio/vnd.nokia.mobile-xmf',
            'vbk' => 'audio/vnd.nortel.vbk',
            'ecelp4800' => 'audio/vnd.nuera.ecelp4800',
            'ecelp7470' => 'audio/vnd.nuera.ecelp7470',
            'ecelp9600' => 'audio/vnd.nuera.ecelp9600',
            'smp3' => 'audio/vnd.sealedmedia.softseal.mpeg',
            'vox' => 'audio/voxware',
            'aif' => 'audio/x-aiff',
            'mp2' => 'audio/x-mpeg',
            'mpu' => 'audio/x-mpegurl',
            'rm' => 'audio/x-pn-realaudio',
            'rpm' => 'audio/x-pn-realaudio-plugin',
            'ra' => 'audio/x-realaudio',
            'wav' => 'audio/x-wav',
            'emb' => 'chemical/x-embl-dl-nucleotide',
            'cube' => 'chemical/x-gaussian-cube',
            'gau' => 'chemical/x-gaussian-input',
            'jdx' => 'chemical/x-jcamp-dx',
            'mol' => 'chemical/x-mdl-molfile',
            'rxn' => 'chemical/x-mdl-rxnfile',
            'tgf' => 'chemical/x-mdl-tgf',
            'mop' => 'chemical/x-mopac-input',
            'pdb' => 'x-chemical/x-pdb',
            'scr' => 'chemical/x-rasmol',
            'xyz' => 'x-chemical/x-xyz',
            'dwf' => 'x-drawing/dwf',
            'ivr' => 'i-world/i-vrml',
            'bmp' => 'image/x-bmp',
            'cod' => 'image/cis-cod',
            'fif' => 'image/fif',
            'gif' => 'image/gif',
            'ief' => 'image/ief',
            'jp2' => 'image/jp2',
            'jpg' => 'image/jpeg',
            'jpm' => 'image/jpm',
            'jpf' => 'image/jpx',
            'pic' => 'image/pict',
            'png' => 'image/x-png',
            'tga' => 'image/targa',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'svf' => 'image/vn-svf',
            'dgn' => 'image/vnd.dgn',
            'djvu' => 'image/vnd.djvu',
            'dwg' => 'image/vnd.dwg',
            'pgb' => 'image/vnd.glocalgraphics.pgb',
            'ico' => 'image/vnd.microsoft.icon',
            'mdi' => 'image/vnd.ms-modi',
            'spng' => 'image/vnd.sealed.png',
            'sgif' => 'image/vnd.sealedmedia.softseal.gif',
            'sjpg' => 'image/vnd.sealedmedia.softseal.jpg',
            'wbmp' => 'image/vnd.wap.wbmp',
            'ras' => 'image/x-cmu-raster',
            'fh4' => 'image/x-freehand',
            'pnm' => 'image/x-portable-anymap',
            'pbm' => 'image/x-portable-bitmap',
            'pgm' => 'image/x-portable-graymap',
            'ppm' => 'image/x-portable-pixmap',
            'rgb' => 'image/x-rgb',
            'xbm' => 'image/x-xbitmap',
            'xpm' => 'image/x-xpixmap',
            'xwd' => 'image/x-xwindowdump',
            'igs' => 'model/iges',
            'msh' => 'model/mesh',
            'x_b' => 'model/vnd.parasolid.transmit.binary',
            'x_t' => 'model/vnd.parasolid.transmit.text',
            'wrl' => 'x-world/x-vrml',
            'csv' => 'text/comma-separated-values',
            'css' => 'text/css',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'rst' => 'text/prs.fallenstein.rst',
            'rtx' => 'text/richtext',
            'sgml' => 'text/x-sgml',
            'tsv' => 'text/tab-separated-values',
            'ccc' => 'text/vnd.net2phone.commcenter.command',
            'jad' => 'text/vnd.sun.j2me.app-descriptor',
            'si' => 'text/vnd.wap.si',
            'sl' => 'text/vnd.wap.sl',
            'wml' => 'text/vnd.wap.wml',
            'wmls' => 'text/vnd.wap.wmlscript',
            'hdml' => 'text/x-hdml',
            'etx' => 'text/x-setext',
            'talk' => 'text/x-speech',
            'vcs' => 'text/x-vcalendar',
            'vcf' => 'text/x-vcard',
            'xml' => 'text/xml',
            'uvr' => 'ulead/vrml',
            '3gp' => 'video/3gpp',
            'dl' => 'video/dl',
            'gl' => 'video/gl',
            'mj2' => 'video/mj2',
            'mpeg' => 'video/mpeg',
            'mov' => 'video/quicktime',
            'vdo' => 'video/vdo',
            'viv' => 'video/vivo',
            'fvt' => 'video/vnd.fvt',
            'mxu' => 'video/vnd.mpegurl',
            'nim' => 'video/vnd.nokia.interleaved-multimedia',
            'mp4' => 'video/vnd.objectvideo',
            's11' => 'video/vnd.sealed.mpeg1',
            'smpg' => 'video/vnd.sealed.mpeg4',
            'sswf' => 'video/vnd.sealed.swf',
            'smov' => 'video/vnd.sealedmedia.softseal.mov',
            'vivo' => 'video/vnd.vivo',
            'fli' => 'video/x-fli',
            'wmv' => 'video/x-ms-wmv',
            'avi' => 'video/x-msvideo',
            'movie' => 'video/x-sgi-movie',
            'ice' => 'x-conference/x-cooltalk',
            'd' => 'x-world/x-d96',
            'svr' => 'x-world/x-svr',
            'vrw' => 'x-world/x-vream'
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    public static function isDisplayableFileType(string $fileType): bool
    {
        $displayableTypes = [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/svg+xml',
            'application/pdf',
        ];

        return in_array($fileType, $displayableTypes);
    }
}