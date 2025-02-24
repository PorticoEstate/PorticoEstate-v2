import {useMemo} from "react";
const colors: string[] = [
	"#FF9999", // Lys rød                           0
	"#D4FFBF", // Lys grønn                         1
	"#99CCFF", // Lys blå                           2
	"#FFF2CC", // Lys gul                           3
	"#FF99CC", // Lys magenta                       4
	"#CCF6F3", // Lys cyan                          5
	"#FFBDA7", // Lys koral                         6
	"#B8CC99", // Lys olivengrønn                   7
	"#FFDAB9", // Lys oransje                       8
	"#CCFFFF", // Lys aqua                          9
	"#E9FFCC", // Lys gul-grønn                     10
	"#E6E6FA", // Lys lilla (Light purple)          11
	"#FFB6C1", // Lys rosa (Light pink)             12
	"#AFEEEE", // Lys turkis (Light turquoise)      13
	"#D3D3D3", // Lys grå (Light gray)              14
	"#FFFACD", // Lys gull (Light gold)             15
	"#C0C0C0", // Lys sølv (Light silver)           16
	"#FF6666", // Medium rød                        17
	"#A3FF7F", // Medium grønn                      18
	"#6699FF", // Medium blå                        19
	"#FFE680", // Medium gul                        20
	"#FF66B2", // Medium magenta                    21
	"#66D9E8", // Medium cyan                       22
	"#FF7F50", // Medium koral                      23
	"#7BA02D", // Medium olivengrønn                24
	"#FFA500", // Medium oransje                    25
	"#00FFFF", // Medium aqua                       26
	"#A52A2A", // Medium brun                       27
	"#C2FF8C", // Medium gul-grønn                  28
	"#9370DB", // Medium lilla (Medium purple)      29
	"#FF69B4", // Medium rosa (Medium pink)         30
	"#40E0D0", // Medium turkis (Medium turquoise)  31
	"#A9A9A9", // Medium grå (Medium gray)          32
	"#FFD700", // Medium gull (Medium gold)         33
	"#A8A9AD", // Medium sølv (Medium silver)       34
	"#CC3333", // Mørk rød                          35
	"#66CC4D", // Mørk grønn                        36
	"#3366CC", // Mørk blå                          37
	"#CC3380", // Mørk magenta                      38
	"#3393A3", // Mørk cyan                         39
	"#CC5A32", // Mørk koral                        40
	"#556B2F", // Mørk olivengrønn                  41
	"#FF8C00", // Mørk oransje                      42
	"#0099A4", // Mørk aqua                         43
	"#7B241C", // Mørk brun                         44
	"#99CC33", // Mørk gul-grønn                    45
	"#CCB833", // Mørk gul                          46
	"#800080", // Mørk lilla (Dark purple)          47
	"#C71585", // Mørk rosa (Dark pink)             48
	"#008080", // Mørk turkis (Dark turquoise)      49
	"#696969", // Mørk grå (Dark gray)              50
	"#B8860B", // Mørk gull (Dark gold)             51
	"#6C7A89", // Mørk sølv (Dark silver)           52
	"#800000", // Mørk maroon                       53
	"#000080", // Mørk marineblå (Dark navy blue)   54
	"#000000", // Svart (Black)                     55
	"#FFFFFF"  // Hvit (White)                      56
];

export const useColours = (): Array<string> | undefined => {
    return useMemo(() => colors, []);
}


export enum ColourIndex {
	// Light colors
	LysRod = 0,
	LysGronn = 1,
	LysBla = 2,
	LysGul = 3,
	LysMagenta = 4,
	LysCyan = 5,
	LysKoral = 6,
	LysOlivengronn = 7,
	LysOransje = 8,
	LysAqua = 9,
	LysGulGronn = 10,
	LysLilla = 11,
	LysRosa = 12,
	LysTurkis = 13,
	LysGra = 14,
	LysGull = 15,
	LysSolv = 16,

	// Medium colors
	MediumRod = 17,
	MediumGronn = 18,
	MediumBla = 19,
	MediumGul = 20,
	MediumMagenta = 21,
	MediumCyan = 22,
	MediumKoral = 23,
	MediumOlivengronn = 24,
	MediumOransje = 25,
	MediumAqua = 26,
	MediumBrun = 27,
	MediumGulGronn = 28,
	MediumLilla = 29,
	MediumRosa = 30,
	MediumTurkis = 31,
	MediumGra = 32,
	MediumGull = 33,
	MediumSolv = 34,

	// Dark colors
	MorkRod = 35,
	MorkGronn = 36,
	MorkBla = 37,
	MorkMagenta = 38,
	MorkCyan = 39,
	MorkKoral = 40,
	MorkOlivengronn = 41,
	MorkOransje = 42,
	MorkAqua = 43,
	MorkBrun = 44,
	MorkGulGronn = 45,
	MorkGul = 46,
	MorkLilla = 47,
	MorkRosa = 48,
	MorkTurkis = 49,
	MorkGra = 50,
	MorkGull = 51,
	MorkSolv = 52,
	MorkMaroon = 53,
	MorkMarinebla = 54,

	// Basic colors
	Svart = 55,
	Hvit = 56
}