To produce the test for work with rid `XXX` run

```sh
mkdir XXX
curl -o XXX/marc-XXX.xml 'https://www.tbrc.org/public?module=work&query=marc&args=XXX'
curl -o XXX/XXX.xml https://www.tbrc.org/xmldoc?rid=XXX
curl -o XXX/MXXX.xml https://www.tbrc.org/xmldoc?rid=MXXX
```
