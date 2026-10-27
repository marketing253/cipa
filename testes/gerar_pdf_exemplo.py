"""PDF fictício no formato de relatório de RH (2 páginas, títulos repetidos) para testar a
importação de colaboradores por PDF.  Uso: python gerar_pdf_exemplo.py [saida.pdf]"""
import sys
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

saida = sys.argv[1] if len(sys.argv) > 1 else "lista-exemplo.pdf"
NOMES = ["Ana Paula Souza", "Bruno Henrique Lima", "Carla Dias Rocha", "Diego Martins", "Elisa Prado",
         "Fábio Nunes", "Gabriela Torres", "Hugo Almeida", "Isabela Castro", "João Pereira da Silva"]
LINHAS = []
for i in range(60):
    nome = NOMES[i % 10] + ("" if i < 10 else " %d" % (i // 10 + 1))
    cpf = "%03d.%03d.%03d-%02d" % (100 + i, 200 + i, 300 + i, i % 100)
    cargo = "" if i == 7 else ["Motorista", "Cobrador", "Mecânico", "Fiscal"][i % 4]
    LINHAS.append((str(30001 + i), nome, cpf, ["Tráfego", "Oficina", "Administrativo"][i % 3], cargo))

c = canvas.Canvas(saida, pagesize=A4)
X = [40, 110, 300, 400, 490]
TIT = ["Matricula", "Nome Completo", "CPF", "Lotação", "Cargo"]
por_pag = 35
for k in range(0, len(LINHAS), por_pag):
    c.setFont("Helvetica-Bold", 13); c.drawString(40, 800, "Relação de colaboradores ativos")
    c.setFont("Helvetica-Bold", 9)
    for x, t in zip(X, TIT):
        c.drawString(x, 770, t)
    c.setFont("Helvetica", 9)
    y = 752
    for lin in LINHAS[k:k + por_pag]:
        for x, t in zip(X, lin):
            if t:
                c.drawString(x, y, t)
        y -= 19
    c.drawString(40, 30, "Página %d" % (k // por_pag + 1))
    c.showPage()
c.save()
print("ok", saida, len(LINHAS), "linhas")
