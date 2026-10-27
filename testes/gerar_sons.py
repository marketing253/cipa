"""Gera os sons da urna já prontos para o celular (tocados como mídia, não por Web Audio):
public/fim-urna.mp3  trecho do FIM do fim.mp3 (1,2 s a 2,25 s) com volume normalizado, sem ganho na hora
public/tecla.mp3     bipe curto de tecla (1050 Hz, quadrada suave), como a urna eletrônica
Uso: python gerar_sons.py
"""
import os
import subprocess
import wave

import imageio_ffmpeg
import numpy as np

AQUI = os.path.dirname(os.path.abspath(__file__))
PUB = os.path.join(os.path.dirname(AQUI), "public")
FF = imageio_ffmpeg.get_ffmpeg_exe()

# FIM: corta, normaliza o pico e faz fade curtinho para não estalar
subprocess.run([FF, "-y", "-i", os.path.join(PUB, "fim.mp3"),
                "-af", "atrim=1.2:2.25,asetpts=PTS-STARTPTS,highpass=f=120,dynaudnorm=f=150:g=3:p=0.95,"
                       "loudnorm=I=-12:TP=-1:LRA=7,volume=5.5dB,alimiter=limit=0.89:level=false,afade=t=in:d=0.01,afade=t=out:st=0.98:d=0.07",
                "-ac", "1", "-ar", "44100", "-b:a", "128k", os.path.join(PUB, "fim-urna.mp3")],
               check=True, capture_output=True)

# tecla: 75 ms, quadrada com bordas suaves
sr, dur = 44100, 0.075
t = np.arange(int(sr * dur)) / sr
y = np.sign(np.sin(2 * np.pi * 1050 * t)) * 0.4
env = np.minimum(1, np.minimum(t / 0.004, (dur - t) / 0.012))
pcm = (y * env * 32767).astype(np.int16)
tmp = os.path.join(AQUI, "_tecla.wav")
with wave.open(tmp, "wb") as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr); w.writeframes(pcm.tobytes())
subprocess.run([FF, "-y", "-i", tmp, "-af", "apad=pad_dur=0.03", "-ac", "1", "-ar", "44100", "-b:a", "96k",
                os.path.join(PUB, "tecla.mp3")], check=True, capture_output=True)
os.remove(tmp)
for n in ("fim-urna.mp3", "tecla.mp3"):
    print(n, os.path.getsize(os.path.join(PUB, n)), "bytes")
