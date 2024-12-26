from selenium import webdriver
from selenium.webdriver.common.by import By

driver = webdriver.Chrome()
driver.get("https://www.lego.com/de-de/service/buildinginstructions/31099")

title = driver.title

driver.implicitly_wait(0.5)

print(driver.find_element(by=By.xpath, value="/html/body/div[2]/div/div[2]/div[1]/div/div/h1"))

driver.quit()