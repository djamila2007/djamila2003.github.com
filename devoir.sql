CREATE DATABASE devoir;

USE devoir;

CREATE TABLE eleve (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100),
    prenom VARCHAR(100),
    age INT,
    classe VARCHAR(50)
);

CREATE TABLE note (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eleve_id INT,
    matiere VARCHAR(100),
    valeur DECIMAL(5,2),
    date_enregistrement DATETIME,
    FOREIGN KEY (eleve_id) REFERENCES eleve(id)
);