import net.sf.jasperreports.engine.JasperCompileManager;

public class TestCompile {
	public static void main(String[] args) {
		if (args.length < 1) {
			System.out.println("Usage: java TestCompile <jrxml_file>");
			System.exit(1);
		}
		
		try {
			String jasperFile = JasperCompileManager.compileReportToFile(args[0]);
			System.out.println("SUCCESS: Compiled to " + jasperFile);
			System.exit(0);
		} catch (Exception e) {
			System.err.println("ERROR: " + e.getMessage());
			e.printStackTrace(System.err);
			System.exit(1);
		}
	}
}
