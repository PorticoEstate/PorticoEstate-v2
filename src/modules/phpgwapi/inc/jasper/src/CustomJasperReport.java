import java.sql.Connection;
import java.sql.SQLException;
import java.util.HashMap;

import net.sf.jasperreports.engine.JREmptyDataSource;
import net.sf.jasperreports.engine.JRException;
import net.sf.jasperreports.engine.JasperCompileManager;
import net.sf.jasperreports.engine.JasperExportManager;
import net.sf.jasperreports.engine.JasperFillManager;
import net.sf.jasperreports.engine.JasperPrint;
import net.sf.jasperreports.engine.JasperReport;
import net.sf.jasperreports.engine.export.HtmlExporter;
import net.sf.jasperreports.engine.export.JRCsvExporter;
import net.sf.jasperreports.engine.export.ooxml.JRDocxExporter;
import net.sf.jasperreports.engine.export.ooxml.JRXlsxExporter;
import net.sf.jasperreports.export.SimpleExporterInput;
import net.sf.jasperreports.export.SimpleHtmlExporterOutput;
import net.sf.jasperreports.export.SimpleOutputStreamExporterOutput;
import net.sf.jasperreports.export.SimpleWriterExporterOutput;
import net.sf.jasperreports.export.SimpleXlsxReportConfiguration;

class CustomJasperReport {

	private String name;
	private String source;
	private JasperPrint jasperPrint;

	public CustomJasperReport(String name, String source) {
		this.name = name;
		this.source = source;
		this.jasperPrint = null;
	}

	public void generateReport(HashMap<String, Object> parameters, JasperConnection jc) {
		JasperReport jasperReport = null;

		try {
			jasperReport = JasperCompileManager.compileReport(this.source);
		} catch (Exception e) {
			System.exit(201);
		}

		Connection connection = jc.makeConnection();

		try {
			if (connection != null) {
				this.jasperPrint = JasperFillManager.fillReport(
					jasperReport,
					parameters, 
					connection
				);
			} else {
				this.jasperPrint = JasperFillManager.fillReport(
					jasperReport,
					null,
					new JREmptyDataSource(50)
				);
			}
		} catch (JRException e1) {
			System.exit(202);
		}

		try {
			if (connection != null) {
				connection.close();
			}
		} catch (SQLException e) {
			System.err.println("Unable to close connection");
			e.printStackTrace();
		}
	}

	public void generatePdf() {
		if (this.jasperPrint == null) {
			System.exit(203);
		}
		try {
			JasperExportManager.exportReportToPdfStream(this.jasperPrint, System.out);
		} catch (JRException e) {
			System.exit(204);
		}
	}

	public void generateCSV() {
		if (this.jasperPrint == null) {
			System.exit(203);
		}
		JRCsvExporter csvexp = new JRCsvExporter();
		csvexp.setExporterInput(new SimpleExporterInput(this.jasperPrint));
		csvexp.setExporterOutput(new SimpleWriterExporterOutput(System.out));
		try {
			csvexp.exportReport();
		} catch (JRException e) {
			System.exit(205);
		}
	}

	public void generateJRXls() {
		// Legacy XLS exporter removed in JasperReports 7.x
		// Fallback to XLSX format
		generateJExcel();
	}

	public void generateJExcel() {
		if (this.jasperPrint == null) {
			System.exit(203);
		}
		try {
			// Ensure stdout is flushed and in binary mode
			System.out.flush();
			
			JRXlsxExporter xlsx = new JRXlsxExporter();
			xlsx.setExporterInput(new SimpleExporterInput(this.jasperPrint));
			xlsx.setExporterOutput(new SimpleOutputStreamExporterOutput(System.out));
			
			SimpleXlsxReportConfiguration configuration = new SimpleXlsxReportConfiguration();
			configuration.setOnePagePerSheet(false);
			configuration.setDetectCellType(true);
			configuration.setCollapseRowSpan(false);
			configuration.setWhitePageBackground(false);
			configuration.setIgnoreGraphics(false);
			configuration.setRemoveEmptySpaceBetweenRows(true);
			configuration.setRemoveEmptySpaceBetweenColumns(true);
			xlsx.setConfiguration(configuration);
			
			xlsx.exportReport();
			System.out.flush();
		} catch (JRException e) {
			System.exit(206);
		}
	}

	public void generateXhtml() {
		if (this.jasperPrint == null) {
			System.exit(203);
		}
		HtmlExporter xhtmlexp = new HtmlExporter();
		xhtmlexp.setExporterInput(new SimpleExporterInput(this.jasperPrint));
		xhtmlexp.setExporterOutput(new SimpleHtmlExporterOutput(System.out));
		try {
			xhtmlexp.exportReport();
		} catch (JRException e) {
			System.exit(218);
		}
	}

	public void generateDocx() {
		if (this.jasperPrint == null) {
			System.exit(203);
		}
		JRDocxExporter docxexp = new JRDocxExporter();
		docxexp.setExporterInput(new SimpleExporterInput(this.jasperPrint));
		docxexp.setExporterOutput(new SimpleOutputStreamExporterOutput(System.out));
		try {
			docxexp.exportReport();
		} catch (JRException e) {
			System.exit(219);
		}
	}

	public String getName() {
		return this.name;
	}
}
